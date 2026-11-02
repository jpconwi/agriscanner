<?php

namespace App\Providers;

use App\Models\Scan;
use App\Policies\ScanPolicy;
use App\Services\Ai\AiAnalysisService;
use App\Services\Ai\Providers\GrokProvider;
use App\Services\Ai\Providers\HuggingFaceProvider;
use App\Services\Storage\FlysystemObjectStore;
use App\Services\Storage\StorageManager;
use App\Support\EnvironmentValidator;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorageManager::class, function () {
            $cfg = config('agriscan.storage');
            $stores = [];
            // Insertion order = priority order. Unknown names in STORAGE_PRIORITY are ignored.
            foreach ($cfg['priority'] as $name) {
                if (isset($cfg['stores'][$name])) {
                    $stores[$name] = new FlysystemObjectStore($name, $cfg['stores'][$name]);
                }
            }

            return new StorageManager($stores, $cfg['max_retries'], $cfg['backoff_ms'], $cfg['replicate']);
        });

        $this->app->singleton(AiAnalysisService::class, function () {
            $ai = config('agriscan.ai');
            $providers = [
                'huggingface' => new HuggingFaceProvider($ai['providers']['huggingface'], $ai['timeout_seconds']),
                'grok' => new GrokProvider($ai['providers']['grok'], $ai['timeout_seconds']),
            ];

            return new AiAnalysisService($providers, $ai['priority'], $ai['max_retries'], $ai['backoff_ms'], $ai['max_backoff_ms']);
        });
    }

    public function boot(): void
    {
        Gate::policy(Scan::class, ScanPolicy::class);

        // @bytes($n) and @date($iso) in views
        Blade::directive('bytes', fn ($e) => "<?php echo \\App\\Support\\Format::bytes($e); ?>");
        Blade::directive('date', fn ($e) => "<?php echo \\App\\Support\\Format::date($e); ?>");

        $this->validateEnvironment();
        $this->configureRateLimiting();

        // Password reset emails link to this app's reset page.
        ResetPassword::createUrlUsing(fn ($user, string $token) => route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]));
    }

    /** Fails fast on critical misconfiguration in production; optional services only warn. Never logs values. */
    private function validateEnvironment(): void
    {
        if ($this->app->runningUnitTests() || ($this->app->runningInConsole() && ! $this->app->isProduction())) {
            return;
        }
        $problems = EnvironmentValidator::problems();
        foreach ($problems['warnings'] as $w) {
            Log::warning('config: '.$w);
        }
        if ($problems['critical'] !== [] && $this->app->isProduction() && ! $this->app->runningInConsole()) {
            throw new \RuntimeException('Invalid configuration: '.implode(' ', $problems['critical']));
        }
    }

    private function configureRateLimiting(): void
    {
        $rl = config('agriscan.rate_limits');

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute($rl['api_per_minute'])->by($r->user()?->id ?: $r->ip()));
        // Keyed by email+IP so one attacker cannot lock out everyone, and one IP cannot spray many accounts.
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute($rl['login_per_minute'])->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute($rl['login_per_minute'] * 4)->by($r->ip()),
        ]);
        RateLimiter::for('auth-sensitive', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute($rl['uploads_per_minute'])->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('ai', fn (Request $r) => Limit::perHour($rl['ai_per_hour'])->by($r->user()?->id ?: $r->ip()));
    }
}
