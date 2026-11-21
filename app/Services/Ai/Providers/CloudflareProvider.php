<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\VisionProvider;
use App\Services\Ai\Dto\AnalysisResult;
use App\Services\Ai\Dto\ImagePayload;
use App\Services\Ai\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cloudflare Workers AI over its REST API (no SDK). Uses a vision-language model, so like the other chat
 * providers it reports no calibrated confidence. It identifies the crop and condition itself (preferring names from
 * the KNOWN disease list) and writes general guidance, which the matcher only uses when the library has no entry.
 *
 * Free plan: Cloudflare gives a daily allowance and then answers with an error (it does not bill), which this
 * provider maps to a non-retried "rate_limited" so the chain moves straight on to the next provider.
 *
 * Llama 3.2 Vision needs a one-time licence acceptance per account: send the prompt "agree" once (see README).
 */
class CloudflareProvider implements VisionProvider
{
    public function __construct(private readonly array $config, private readonly int $timeout = 45)
    {
    }

    public function name(): string
    {
        return 'cloudflare';
    }

    public function model(): ?string
    {
        return $this->config['model'] ?: null;
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false)
            && filled($this->config['account_id'] ?? null)
            && filled($this->config['api_token'] ?? null)
            && filled($this->config['model'] ?? null);
    }

    private function base(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://api.cloudflare.com/client/v4'), '/').'/accounts/'.$this->config['account_id'];
    }

    public function analyze(ImagePayload $image, array $knownConditions = []): AnalysisResult
    {
        try {
            $response = Http::withToken($this->config['api_token'])
                ->acceptJson()->asJson()
                ->connectTimeout(10)->timeout($this->timeout)
                ->post($this->base().'/ai/run/'.ltrim($this->config['model'], '/'), [
                    'temperature' => 0,
                    'max_tokens' => ChatVision::MAX_TOKENS,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => ChatVision::prompt($knownConditions)],
                            ['type' => 'image_url', 'image_url' => ['url' => $image->dataUri()]],
                        ],
                    ]],
                ]);
        } catch (ConnectionException) {
            throw new ProviderException('timeout', 'Cloudflare did not respond in time.', true);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }
        if ($response->json('success') === false) {
            throw new ProviderException('provider_rejected', 'Cloudflare rejected the request.', false, $response->status());
        }

        $parsed = ChatVision::parse($this->text($response));

        return new AnalysisResult('cloudflare', $this->config['model'], $parsed['crop'], $parsed['condition'], $parsed['is_healthy'], null, [], null, $parsed['details']);
    }

    /** Workers AI returns {result: {response: "..."}}; some models use the OpenAI "choices" shape instead. */
    private function text(Response $r): string
    {
        $out = $r->json('result.response') ?? $r->json('result.choices.0.message.content') ?? $r->json('choices.0.message.content') ?? '';

        return is_string($out) ? $out : (string) json_encode($out);
    }

    /** Cloudflare-specific cases first, then the shared HTTP mapping. Response bodies are inspected, never echoed. */
    private function failure(Response $r): ProviderException
    {
        $code = (int) $r->json('errors.0.code', 0);
        $message = strtolower((string) $r->json('errors.0.message', ''));

        if ($code === 5016 || str_contains($message, 'agree')) {
            return new ProviderException('model_unavailable', 'Cloudflare model licence not accepted yet: send the prompt "agree" to the model once.', false, $r->status());
        }
        if ($code === 4006 || str_contains($message, 'daily free allocation')) {
            return new ProviderException('rate_limited', 'Cloudflare daily free allocation is used up.', false, $r->status());
        }

        return ChatVision::httpError($r, 'Cloudflare');
    }

    public function verify(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'status' => 'unconfigured', 'message' => 'Set CLOUDFLARE_ACCOUNT_ID, CLOUDFLARE_AI_API_TOKEN and CLOUDFLARE_AI_MODEL.'];
        }
        try {
            $r = Http::withToken($this->config['api_token'])->acceptJson()->timeout(15)
                ->get($this->base().'/ai/models/search', ['search' => Str::afterLast($this->config['model'], '/'), 'per_page' => 20]);
        } catch (ConnectionException) {
            return ['ok' => false, 'status' => 'down', 'message' => 'Could not reach the Cloudflare API.'];
        }
        if (in_array($r->status(), [401, 403], true)) {
            return ['ok' => false, 'status' => 'down', 'message' => 'Credentials were rejected. The token needs Workers AI permission for this account.'];
        }
        if (! $r->successful()) {
            return ['ok' => false, 'status' => 'degraded', 'message' => 'API returned HTTP '.$r->status().'.'];
        }
        $names = collect($r->json('result', []))->pluck('name');
        if (! $names->contains($this->config['model'])) {
            return ['ok' => false, 'status' => 'degraded', 'message' => 'Configured model was not found in the Workers AI catalogue.'];
        }

        return ['ok' => true, 'status' => 'healthy', 'message' => 'Model found. Availability of the free daily allowance is confirmed by the first real call.'];
    }
}
