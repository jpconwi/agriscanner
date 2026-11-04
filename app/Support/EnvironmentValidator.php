<?php

namespace App\Support;

/**
 * Reports configuration problems by NAME only. Values are never returned or logged.
 * Critical problems stop the app in production; optional services only produce warnings.
 */
final class EnvironmentValidator
{
    /** @return array{critical: list<string>, warnings: list<string>} */
    public static function problems(): array
    {
        $critical = [];
        $warnings = [];

        if (blank(config('app.key'))) {
            $critical[] = 'APP_KEY is not set.';
        }
        if (app()->isProduction() && config('app.debug')) {
            $critical[] = 'APP_DEBUG must be false in production.';
        }
        $dbUrl = config('database.connections.'.config('database.default').'.url');
        $dbHost = config('database.connections.'.config('database.default').'.host');
        if (config('database.default') === 'pgsql' && blank($dbUrl) && blank($dbHost)) {
            $critical[] = 'Database connection is not configured (set DB_URL or DB_HOST).';
        }
        if (app()->isProduction() && ! str_starts_with((string) config('app.url'), 'https://')) {
            $warnings[] = 'APP_URL should use https in production.';
        }

        $anyAi = false;
        $hf = config('agriscan.ai.providers.huggingface');
        if ($hf['enabled'] && filled($hf['token']) && filled($hf['model'])) {
            $anyAi = true;
        } elseif ($hf['enabled']) {
            $warnings[] = 'Hugging Face is enabled but HUGGINGFACE_API_TOKEN or HUGGINGFACE_MODEL is missing.';
        }
        $grok = config('agriscan.ai.providers.grok');
        if ($grok['enabled'] && (blank($grok['api_key']) || blank($grok['model']) || ! $grok['access_authorized'])) {
            $warnings[] = 'Grok is enabled but GROK_API_KEY, GROK_MODEL or GROK_ACCESS_AUTHORIZED is missing; it will be skipped.';
        } elseif ($grok['enabled']) {
            $anyAi = true;
        }
        if (! $anyAi) {
            $warnings[] = 'No AI provider is fully configured; scans will fail with a clear message.';
        }

        $r2 = config('agriscan.storage.stores.r2');
        $r2Ok = $r2['enabled'] && filled($r2['key']) && filled($r2['secret']) && filled($r2['bucket']) && filled($r2['endpoint']);
        $sec = config('agriscan.storage.stores.secondary');
        $secOk = $sec['enabled'] && filled($sec['key']) && filled($sec['secret']) && filled($sec['bucket']);
        $cl = config('agriscan.storage.stores.cloudinary');
        $clOk = ($cl['enabled'] ?? false) && filled($cl['cloud_name'] ?? null) && filled($cl['api_key'] ?? null) && filled($cl['api_secret'] ?? null);
        if (! $r2Ok && ! $secOk && ! $clOk) {
            $warnings[] = 'No object storage is configured; uploads will be rejected (local disk is never used for permanent storage).';
        }
        if (config('agriscan.storage.replicate') && ! ($r2Ok && $secOk)) {
            $warnings[] = 'STORAGE_REPLICATE is on but both stores are not configured.';
        }

        return ['critical' => $critical, 'warnings' => $warnings];
    }
}
