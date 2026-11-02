<?php

namespace App\Services\Ai;

use App\Models\AiProviderLog;
use App\Services\Ai\Contracts\VisionProvider;
use App\Services\Ai\Dto\AnalysisResult;
use App\Services\Ai\Dto\ImagePayload;
use App\Services\Ai\Exceptions\AnalysisUnavailableException;
use App\Services\Ai\Exceptions\InvalidImageException;
use App\Services\Ai\Exceptions\ProviderException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Runs the configured provider chain (default: Hugging Face -> Grok -> clear failure).
 *
 *  - Unconfigured / unauthorised providers are skipped silently.
 *  - Transient errors (timeouts, 5xx, rate limits) are retried on the same provider with exponential backoff, bounded.
 *  - Permanent provider errors (auth, 402, missing model) skip straight to the next provider; no retry.
 *  - Invalid-input errors stop everything: no retry, no fallback, no invented answer.
 *  - If every provider fails, AnalysisUnavailableException is thrown. A prediction is never fabricated.
 */
class AiAnalysisService
{
    /** @param array<string, VisionProvider> $providers keyed by name */
    public function __construct(
        private readonly array $providers,
        private readonly array $priority,
        private readonly int $maxRetries = 2,
        private readonly int $backoffMs = 500,
        private readonly int $maxBackoffMs = 8000,
    ) {
    }

    /** @return list<VisionProvider> configured providers in priority order */
    public function chain(): array
    {
        $out = [];
        foreach ($this->priority as $name) {
            if (isset($this->providers[$name]) && $this->providers[$name]->isConfigured()) {
                $out[] = $this->providers[$name];
            }
        }

        return $out;
    }

    /** @return array<string, VisionProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    /** @param list<string> $knownConditions */
    public function analyze(ImagePayload $image, array $knownConditions = [], ?int $userId = null): AnalysisResult
    {
        $chain = $this->chain();
        if ($chain === []) {
            throw new AnalysisUnavailableException('Crop analysis is not configured yet, so no result was produced. Please contact the administrator.');
        }

        foreach ($chain as $provider) {
            $attempt = 0;
            while (true) {
                $attempt++;
                $started = microtime(true);
                try {
                    $result = $provider->analyze($image, $knownConditions);
                    $latency = (int) round((microtime(true) - $started) * 1000);
                    $this->log($provider, $attempt, 'success', null, null, $latency, $userId);

                    return $result->withLatency($latency);
                } catch (ProviderException $e) {
                    $latency = (int) round((microtime(true) - $started) * 1000);
                    $this->log($provider, $attempt, 'failure', $e->category, $e->httpStatus, $latency, $userId);
                    Log::warning('ai.provider_failed', ['provider' => $provider->name(), 'category' => $e->category, 'status' => $e->httpStatus, 'attempt' => $attempt]);

                    if ($e->terminal) {
                        throw new InvalidImageException;
                    }
                    if ($e->transient && $attempt <= $this->maxRetries) {
                        Sleep::for(min($this->backoffMs * (2 ** ($attempt - 1)), $this->maxBackoffMs) + random_int(0, 150))->milliseconds();

                        continue;
                    }

                    break; // next provider
                }
            }
        }

        throw new AnalysisUnavailableException;
    }

    private function log(VisionProvider $p, int $attempt, string $outcome, ?string $category, ?int $status, int $latency, ?int $userId): void
    {
        try {
            AiProviderLog::create([
                'user_id' => $userId, 'provider' => $p->name(), 'model' => $p->model(), 'attempt' => $attempt,
                'outcome' => $outcome, 'error_category' => $category, 'http_status' => $status, 'latency_ms' => $latency,
            ]);
        } catch (\Throwable $e) {
            // Telemetry must never turn a successful analysis into a failure.
            Log::warning('ai.log_failed');
        }
    }
}
