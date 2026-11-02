<?php

namespace App\Services\Quota;

use App\Models\Scan;
use App\Models\StorageQuota;
use App\Models\StorageUsage;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Storage\Exceptions\StoreException;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\DB;

/**
 * Per-account (logical) storage accounting.
 *
 *  - used_bytes     : confirmed, available files. Each logical file counts ONCE, however many replicas exist.
 *  - reserved_bytes : uploads in flight. Counted against the quota so concurrent uploads cannot jointly overshoot.
 *
 * This is the user's allowance. It is unrelated to Cloudflare R2's or Neon's own limits.
 */
class QuotaService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function ensureRows(User $user): array
    {
        $quota = StorageQuota::firstOrCreate(['user_id' => $user->id], ['quota_bytes' => $this->settings->get('default_quota_bytes')]);
        $usage = StorageUsage::firstOrCreate(['user_id' => $user->id], []);

        return [$quota, $usage];
    }

    public function quotaBytes(User $user): int
    {
        return $this->ensureRows($user)[0]->quota_bytes;
    }

    public function setQuota(User $user, int $bytes): StorageQuota
    {
        [$quota] = $this->ensureRows($user);
        $quota->update(['quota_bytes' => $bytes]);

        return $quota;
    }

    /**
     * Atomically reserve space for an upload. Throws if it would exceed the quota.
     * Existing files are never touched, even if a lowered quota leaves the account over its limit.
     */
    public function reserve(User $user, int $bytes): void
    {
        $this->ensureRows($user);
        DB::transaction(function () use ($user, $bytes) {
            $usage = StorageUsage::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $quota = StorageQuota::where('user_id', $user->id)->firstOrFail();

            if ($usage->used_bytes + $usage->reserved_bytes >= $quota->quota_bytes) {
                throw QuotaExceededException::full();
            }
            if ($usage->used_bytes + $usage->reserved_bytes + $bytes > $quota->quota_bytes) {
                throw QuotaExceededException::wouldExceed();
            }
            $usage->increment('reserved_bytes', $bytes);
        });
    }

    /** Upload confirmed durable: move the reservation into real usage. */
    public function commit(User $user, int $bytes): void
    {
        DB::transaction(function () use ($user, $bytes) {
            $usage = StorageUsage::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $usage->update([
                'reserved_bytes' => max(0, $usage->reserved_bytes - $bytes),
                'used_bytes' => $usage->used_bytes + $bytes,
                'file_count' => $usage->file_count + 1,
            ]);
        });
    }

    /** Upload failed or was abandoned: give the reservation back. */
    public function release(User $user, int $bytes): void
    {
        DB::transaction(function () use ($user, $bytes) {
            $usage = StorageUsage::where('user_id', $user->id)->lockForUpdate()->first();
            $usage?->update(['reserved_bytes' => max(0, $usage->reserved_bytes - $bytes)]);
        });
    }

    /** Only call after every replica deletion has been confirmed. */
    public function decrement(User $user, int $bytes): void
    {
        DB::transaction(function () use ($user, $bytes) {
            $usage = StorageUsage::where('user_id', $user->id)->lockForUpdate()->first();
            $usage?->update([
                'used_bytes' => max(0, $usage->used_bytes - $bytes),
                'file_count' => max(0, $usage->file_count - 1),
            ]);
        });
    }

    public function level(float $percent): string
    {
        $s = $this->settings->all();

        return match (true) {
            $percent >= 100 => 'full',
            $percent >= $s['critical_percent'] => 'critical',
            $percent >= $s['warn_percent'] => 'warning',
            default => 'ok',
        };
    }

    /** Real numbers computed from database records. */
    public function usageFor(User $user): array
    {
        [$quota, $usage] = $this->ensureRows($user);
        $quotaBytes = $quota->quota_bytes;
        $percent = $quotaBytes > 0 ? min(100.0, round($usage->used_bytes / $quotaBytes * 100, 2)) : 100.0;

        return [
            'used_bytes' => $usage->used_bytes,
            'quota_bytes' => $quotaBytes,
            'remaining_bytes' => max(0, $quotaBytes - $usage->used_bytes - $usage->reserved_bytes),
            'percent_used' => $percent,
            'image_count' => StoredFile::where('user_id', $user->id)->where('status', StoredFile::AVAILABLE)->count(),
            'scan_count' => Scan::where('user_id', $user->id)->count(),
            'failed_uploads' => StoredFile::where('user_id', $user->id)->where('status', StoredFile::FAILED)->count(),
            'pending_uploads' => StoredFile::where('user_id', $user->id)->where('status', StoredFile::PENDING)->count(),
            'warning_level' => $this->level($percent),
            'last_reconciled_at' => $usage->last_reconciled_at?->toISOString(),
        ];
    }

    /**
     * Recompute usage from stored_files (logical, one count per file). With $manager, also verifies each
     * available file's object still exists with the recorded size and flags mismatches as failed.
     *
     * @return array{users_fixed:int, files_checked:int, files_flagged:int}
     */
    public function reconcile(?User $only = null, ?StorageManager $manager = null, int $verifyLimit = 500): array
    {
        $fixed = 0;
        $checked = 0;
        $flagged = 0;

        $users = $only ? collect([$only]) : User::query()->get(['id']);
        foreach ($users as $user) {
            $this->ensureRows($user);

            if ($manager !== null && $checked < $verifyLimit) {
                StoredFile::where('user_id', $user->id)->where('status', StoredFile::AVAILABLE)->with('replicas')->each(function (StoredFile $f) use ($manager, &$checked, &$flagged, $verifyLimit) {
                    if ($checked >= $verifyLimit) {
                        return false;
                    }
                    $checked++;
                    $replica = $f->replicas->firstWhere('status', 'available');
                    $store = $replica ? $manager->store($replica->provider) : null;
                    if (! $store?->isConfigured()) {
                        return true; // cannot verify now; leave untouched
                    }
                    try {
                        if ($store->size($replica->object_key) !== $f->size_bytes) {
                            $f->update(['status' => StoredFile::FAILED, 'failure_reason' => 'Object missing or size mismatch during reconciliation.']);
                            $flagged++;
                        }
                    } catch (StoreException) {
                        // provider unreachable: do not change anything
                    }

                    return true;
                });
            }

            DB::transaction(function () use ($user, &$fixed) {
                $usage = StorageUsage::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                $used = (int) StoredFile::where('user_id', $user->id)->where('status', StoredFile::AVAILABLE)->sum('size_bytes');
                $count = StoredFile::where('user_id', $user->id)->where('status', StoredFile::AVAILABLE)->count();
                $reserved = (int) StoredFile::where('user_id', $user->id)->where('status', StoredFile::PENDING)->sum('size_bytes');
                if ($usage->used_bytes !== $used || $usage->file_count !== $count || $usage->reserved_bytes !== $reserved) {
                    $fixed++;
                }
                $usage->update(['used_bytes' => $used, 'file_count' => $count, 'reserved_bytes' => $reserved, 'last_reconciled_at' => now()]);
            });
        }

        return ['users_fixed' => $fixed, 'files_checked' => $checked, 'files_flagged' => $flagged];
    }
}
