<?php

use Illuminate\Support\Facades\Schedule;

// Runs when the scheduler is triggered (a worker, `schedule:work`, or POST /api/v1/internal/schedule).
Schedule::command('agriscan:health-check')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('agriscan:process-pending')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('agriscan:reconcile-storage')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [\App\Models\AiProviderLog::class, \App\Models\SystemHealthCheck::class]])->daily();
