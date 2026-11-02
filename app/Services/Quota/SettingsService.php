<?php

namespace App\Services\Quota;

use App\Models\AppSetting;

/** Runtime-editable settings stored in app_settings with config() defaults. */
class SettingsService
{
    private const KEYS = ['default_quota_bytes', 'warn_percent', 'critical_percent', 'db_warn_percent'];

    /** @return array{default_quota_bytes: int, warn_percent: int, critical_percent: int, db_warn_percent: int} */
    public function all(): array
    {
        $stored = AppSetting::whereIn('key', self::KEYS)->pluck('value', 'key');

        return [
            'default_quota_bytes' => (int) ($stored['default_quota_bytes'] ?? config('agriscan.quota.default_bytes')),
            'warn_percent' => (int) ($stored['warn_percent'] ?? config('agriscan.quota.warn_percent')),
            'critical_percent' => (int) ($stored['critical_percent'] ?? config('agriscan.quota.critical_percent')),
            'db_warn_percent' => (int) ($stored['db_warn_percent'] ?? config('agriscan.health.db_warn_percent')),
        ];
    }

    public function get(string $key): int
    {
        return $this->all()[$key];
    }

    public function update(array $values): array
    {
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $values)) {
                AppSetting::updateOrCreate(['key' => $key], ['value' => (string) (int) $values[$key]]);
            }
        }

        return $this->all();
    }
}
