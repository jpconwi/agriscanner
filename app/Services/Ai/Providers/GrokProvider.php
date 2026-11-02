<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\VisionProvider;
use App\Services\Ai\Dto\AnalysisResult;
use App\Services\Ai\Dto\ImagePayload;
use App\Services\Ai\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * xAI Grok via its OpenAI-compatible API. Optional fallback.
 * It is skipped unless GROK_ENABLED, GROK_API_KEY, GROK_MODEL and GROK_ACCESS_AUTHORIZED are all set:
 * the operator must confirm this account may call the model without unexpected charges.
 */
class GrokProvider implements VisionProvider
{
    public function __construct(private readonly array $config, private readonly int $timeout = 45)
    {
    }

    public function name(): string
    {
        return 'grok';
    }

    public function model(): ?string
    {
        return $this->config['model'] ?: null;
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false)
            && ($this->config['access_authorized'] ?? false)
            && filled($this->config['api_key'] ?? null)
            && filled($this->config['model'] ?? null);
    }

    public function analyze(ImagePayload $image, array $knownConditions = []): AnalysisResult
    {
        try {
            $response = Http::withToken($this->config['api_key'])
                ->acceptJson()->asJson()
                ->connectTimeout(10)->timeout($this->timeout)
                ->post(rtrim($this->config['base_url'], '/').'/chat/completions', [
                    'model' => $this->config['model'],
                    'temperature' => 0,
                    'max_tokens' => 200,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => ChatVision::prompt($knownConditions)],
                            ['type' => 'image_url', 'image_url' => ['url' => $image->dataUri(), 'detail' => 'high']],
                        ],
                    ]],
                ]);
        } catch (ConnectionException) {
            throw new ProviderException('timeout', 'Grok did not respond in time.', true);
        }
        if (! $response->successful()) {
            throw ChatVision::httpError($response, 'Grok');
        }
        $parsed = ChatVision::parse((string) $response->json('choices.0.message.content', ''));

        return new AnalysisResult('grok', $this->config['model'], $parsed['crop'], $parsed['condition'], $parsed['is_healthy'], null);
    }

    public function verify(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'status' => 'unconfigured', 'message' => 'Optional. Requires GROK_ENABLED, GROK_API_KEY, GROK_MODEL and GROK_ACCESS_AUTHORIZED=true.'];
        }
        try {
            $r = Http::withToken($this->config['api_key'])->acceptJson()->timeout(15)->get(rtrim($this->config['base_url'], '/').'/models');
        } catch (ConnectionException) {
            return ['ok' => false, 'status' => 'down', 'message' => 'Could not reach the Grok API.'];
        }
        if (in_array($r->status(), [401, 403], true)) {
            return ['ok' => false, 'status' => 'down', 'message' => 'Credentials were rejected.'];
        }
        if (! $r->successful()) {
            return ['ok' => false, 'status' => 'degraded', 'message' => 'API returned HTTP '.$r->status().'.'];
        }
        $ids = collect($r->json('data', []))->pluck('id');
        if (! $ids->contains($this->config['model'])) {
            return ['ok' => false, 'status' => 'degraded', 'message' => 'Configured model is not listed for this account.'];
        }

        return ['ok' => true, 'status' => 'healthy', 'message' => 'Model is listed for this account. Image-input support and pricing must be confirmed in your xAI console.'];
    }
}
