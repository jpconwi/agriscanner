<?php

namespace App\Console\Commands;

use App\Services\Scans\PendingProcessor;
use Illuminate\Console\Command;

class ProcessPendingCommand extends Command
{
    protected $signature = 'agriscan:process-pending {--limit=50}';

    protected $description = 'Retry pending storage operations and replay journalled scan saves';

    public function handle(PendingProcessor $p): int
    {
        $r = $p->run((int) $this->option('limit'));
        $this->info(json_encode($r));

        return self::SUCCESS;
    }
}
