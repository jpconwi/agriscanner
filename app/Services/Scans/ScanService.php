<?php

namespace App\Services\Scans;

use App\Models\Crop;
use App\Models\Disease;
use App\Models\Scan;
use App\Models\ScanResult;
use App\Models\StoredFile;
use App\Models\StoredFileReplica;
use App\Models\User;
use App\Services\Ai\AiAnalysisService;
use App\Services\Ai\DiseaseMatcher;
use App\Services\Ai\Dto\AnalysisResult;
use App\Services\Ai\Dto\ImagePayload;
use App\Services\Ai\Exceptions\AnalysisUnavailableException;
use App\Services\Ai\Exceptions\InvalidImageException;
use App\Services\Quota\QuotaExceededException;
use App\Services\Quota\QuotaService;
use App\Services\Storage\Exceptions\StorageUnavailableException;
use App\Services\Storage\StorageManager;
use App\Support\DatabaseOutage;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Upload -> verify -> reserve quota -> store -> analyse -> save.
 * The scan is only reported as saved once the database confirms it.
 */
class ScanService
{
    public function __construct(
        private readonly ImageInspector $inspector,
        private readonly QuotaService $quota,
        private readonly StorageManager $storage,
        private readonly AiAnalysisService $ai,
        private readonly DiseaseMatcher $matcher,
        private readonly PendingJournal $journal,
    ) {
    }

    /**
     * @throws InvalidImageException|QuotaExceededException|StorageUnavailableException|AnalysisUnavailableException|ScanNotSavedException
     */
    public function create(User $user, UploadedFile $upload, ?int $cropId, string $idempotencyKey): Scan
    {
        // 1. Idempotency: the same key never produces a second scan or a second charge against quota.
        $existing = Scan::where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing?->status === Scan::COMPLETED) {
            return $this->fresh($existing);
        }

        $img = $this->inspector->inspect($upload);

        if ($existing && $existing->file && $existing->file->status === StoredFile::AVAILABLE) {
            // A previous attempt stored the photo but analysis failed: reuse it, do not charge quota twice.
            if ($existing->file->sha256 !== $img['sha256']) {
                throw new InvalidImageException('This retry key was already used with a different photo. Please start a new scan.');
            }

            return $this->analyseAndSave($user, $existing, $img, $cropId);
        }

        // 2. Reserve quota (atomic; counts in-flight uploads).
        $this->quota->reserve($user, $img['size']);
        $file = null;
        $scan = null;
        try {
            [$file, $scan] = DB::transaction(function () use ($user, $img, $cropId, $idempotencyKey, $existing) {
                $uuid = (string) Str::uuid();
                $file = StoredFile::create([
                    'uuid' => $uuid, 'user_id' => $user->id, 'object_key' => $this->storage->keyFor($user->id, $uuid, $img['extension']),
                    'mime' => $img['mime'], 'size_bytes' => $img['size'], 'sha256' => $img['sha256'],
                    'width' => $img['width'], 'height' => $img['height'], 'status' => StoredFile::PENDING,
                ]);
                if ($existing) {
                    $existing->update(['stored_file_id' => $file->id, 'crop_id' => $cropId, 'status' => Scan::PENDING, 'failure_category' => null, 'failure_message' => null]);
                    $scan = $existing;
                } else {
                    $scan = Scan::create([
                        'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'crop_id' => $cropId, 'stored_file_id' => $file->id,
                        'idempotency_key' => $idempotencyKey, 'status' => Scan::PENDING,
                    ]);
                }

                return [$file, $scan];
            });
        } catch (Throwable $e) {
            $this->quota->release($user, $img['size']);
            throw $e;
        }

        // 3. Durable storage (R2 -> secondary), verified before we call it saved.
        try {
            $store = $this->storage->putVerified($file->object_key, $img['bytes'], $img['mime']);
        } catch (StorageUnavailableException $e) {
            $this->abandon($user, $file, $scan, $e->getMessage());
            throw $e;
        }

        DB::transaction(function () use ($user, $file, $store, $img) {
            $file->update(['status' => StoredFile::AVAILABLE, 'failure_reason' => null]);
            StoredFileReplica::updateOrCreate(
                ['stored_file_id' => $file->id, 'provider' => $store->name()],
                ['object_key' => $file->object_key, 'status' => 'available', 'verified_at' => now()],
            );
            $this->quota->commit($user, $img['size']);
        });
        $this->storage->replicateTo($file, $store, $img['bytes']);

        return $this->analyseAndSave($user, $scan->refresh(), $img, $cropId);
    }

    private function analyseAndSave(User $user, Scan $scan, array $img, ?int $cropId): Scan
    {
        $known = Disease::query()->distinct()->orderBy('name')->pluck('name')->all();
        try {
            $result = $this->ai->analyze(new ImagePayload($img['bytes'], $img['mime']), $known, $user->id);
        } catch (InvalidImageException $e) {
            // The photo itself is unusable: remove it entirely and free the space.
            $this->deleteScan($user, $scan);
            throw $e;
        } catch (AnalysisUnavailableException $e) {
            $scan->update(['status' => Scan::FAILED, 'failure_category' => 'analysis_unavailable', 'failure_message' => Str::limit($e->getMessage(), 250)]);
            throw $e;
        }

        return $this->save($user, $scan, $result, $cropId);
    }

    private function save(User $user, Scan $scan, AnalysisResult $result, ?int $cropId): Scan
    {
        $match = $this->matcher->match($result, $cropId);
        $attrs = $this->resultAttributes($result, $match);

        try {
            DB::transaction(function () use ($scan, $attrs) {
                ScanResult::updateOrCreate(['scan_id' => $scan->id], $attrs);
                $scan->update(['status' => Scan::COMPLETED, 'failure_category' => null, 'failure_message' => null]);
            });
        } catch (QueryException $e) {
            if (! DatabaseOutage::isConnectionFailure($e)) {
                throw $e;
            }
            // Never claim it was saved. Record the finished analysis (no image bytes) for replay.
            $where = $this->journal->record('save_scan', 'save_scan:'.$scan->uuid, ['scan_uuid' => $scan->uuid, 'result' => $attrs], $user->id, 'database unavailable');
            throw new ScanNotSavedException($where);
        }

        return $this->fresh($scan);
    }

    /** @return array<string, mixed> */
    public function resultAttributes(AnalysisResult $result, array $match): array
    {
        return [
            'disease_id' => $match['disease']?->id,
            'crop_id' => $match['crop']?->id,
            'predicted_label' => Str::limit($result->conditionLabel, 190, ''),
            'crop_label' => $result->cropLabel ? Str::limit($result->cropLabel, 120, '') : null,
            'is_healthy' => $result->isHealthy,
            'confidence' => $result->confidence,
            'provider' => $result->provider,
            'model' => $result->model,
            'latency_ms' => $result->latencyMs,
            'recommendations' => $match['recommendations'],
            'alternatives' => $result->alternatives ?: null,
        ];
    }

    /** Upload could not be stored: mark failed, give back the reservation, drop the scan shell. */
    private function abandon(User $user, StoredFile $file, Scan $scan, string $reason): void
    {
        DB::transaction(function () use ($user, $file, $scan, $reason) {
            $file->update(['status' => StoredFile::FAILED, 'failure_reason' => Str::limit($reason, 250)]);
            $this->quota->release($user, $file->size_bytes);
            $scan->delete();
        });
    }

    /**
     * Deletes the scan and its photo from EVERY replica. The scan record is only removed once all replicas
     * are confirmed gone, and usage is only decremented after that confirmation.
     *
     * @throws StorageUnavailableException when a replica could not be deleted (nothing user-visible is lost)
     */
    public function deleteScan(User $user, Scan $scan): void
    {
        $file = $scan->file;
        if ($file && $file->status !== StoredFile::FAILED) {
            $wasAvailable = $file->status === StoredFile::AVAILABLE || $file->status === StoredFile::DELETING;
            if (! $this->storage->deleteEverywhere($file)) {
                throw new StorageUnavailableException('The photo could not be removed from storage right now, so the scan was kept. Please try again shortly.');
            }
            DB::transaction(function () use ($user, $file, $scan, $wasAvailable) {
                if ($wasAvailable) {
                    $this->quota->decrement($user, $file->size_bytes);
                }
                $scan->delete();
                $file->delete();
            });

            return;
        }

        DB::transaction(function () use ($scan, $file) {
            $scan->delete();
            $file?->delete();
        });
    }

    public function fresh(Scan $scan): Scan
    {
        return $scan->fresh(['crop', 'file.replicas', 'result.disease', 'result.crop']);
    }
}
