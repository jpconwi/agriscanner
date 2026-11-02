<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Scans\ScanService;
use App\Services\Storage\Exceptions\StorageUnavailableException;
use Illuminate\Support\Facades\DB;

/** Account removal. Photos are deleted from every storage replica BEFORE any database row is dropped. */
class AccountService
{
    public function __construct(private readonly ScanService $scans)
    {
    }

    /** @throws StorageUnavailableException if any photo could not be removed (the account is left intact so nothing is orphaned) */
    public function delete(User $user, ?User $actor = null): void
    {
        foreach ($user->scans()->with('file.replicas')->cursor() as $scan) {
            $this->scans->deleteScan($user, $scan);
        }
        // Files that never reached a scan (failed/pending shells)
        foreach ($user->storedFiles()->with('replicas')->get() as $file) {
            if (! app(\App\Services\Storage\StorageManager::class)->deleteEverywhere($file)) {
                throw new StorageUnavailableException('Some photos could not be removed from storage yet, so the account was not deleted. Please try again shortly.');
            }
        }
        AuditLog::record($actor?->id ?? $user->id, 'user.deleted', 'user', $user->id, ['self' => $actor === null || $actor->id === $user->id]);
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });
    }
}
