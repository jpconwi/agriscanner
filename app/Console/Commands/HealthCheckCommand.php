<?php

namespace App\Console\Commands;

use App\Services\Health\SystemHealthService;
use Illuminate\Console\Command;

class HealthCheckCommand extends Command
{
    protected $signature = 'agriscan:health-check';

    protected $description = 'Probe database, storage and AI providers and record the results';

    public function handle(SystemHealthService $health): int
    {
        foreach ($health->runAll() as $c) {
            $this->line(sprintf('%-24s %s', $c['service'], $c['status']));
        }

        return self::SUCCESS;
    }
}
