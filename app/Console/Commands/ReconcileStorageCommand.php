<?php

namespace App\Console\Commands;

use App\Services\Quota\QuotaService;
use App\Services\Storage\StorageManager;
use Illuminate\Console\Command;

class ReconcileStorageCommand extends Command
{
    protected $signature = 'agriscan:reconcile-storage {--no-verify : Skip checking objects in the buckets}';

    protected $description = 'Recompute per-account usage from stored file records and verify object metadata';

    public function handle(QuotaService $quota, StorageManager $storage): int
    {
        $r = $quota->reconcile(null, $this->option('no-verify') ? null : $storage);
        $this->info(json_encode($r));

        return self::SUCCESS;
    }
}
