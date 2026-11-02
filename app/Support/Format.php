<?php

namespace App\Support;

use Carbon\Carbon;

/** Presentation helpers shared by Blade views. Unknown values render as "Unknown", never as an invented number. */
final class Format
{
    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'Unknown';
        }
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $v = $bytes / (1024 ** $i);

        return ($v >= 100 || $i === 0 ? number_format($v, 0) : number_format($v, 1)).' '.$units[$i];
    }

    public static function date(Carbon|string|null $value): string
    {
        if (! $value) {
            return '—';
        }

        return Carbon::parse($value)->timezone(config('app.timezone'))->format('M j, Y g:i A');
    }

    /** Badge colour class for a health/status word. */
    public static function tone(?string $status): string
    {
        return match ($status) {
            'healthy', 'ok', 'completed', 'available' => 'badge-success',
            'degraded', 'pending', 'processing', 'warning' => 'badge-warning',
            'down', 'failed', 'full', 'critical' => 'badge-danger',
            default => 'badge-neutral',
        };
    }

    /** Level -> Tailwind background class for progress bars. */
    public static function levelColor(?string $level): string
    {
        return match ($level) {
            'warning' => 'bg-amber-500', 'critical' => 'bg-orange-600', 'full' => 'bg-clay-600', default => 'bg-leaf-500',
        };
    }
}
