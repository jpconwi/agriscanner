<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Exceptions\ProviderException;
use App\Support\LabelNormalizer;
use Illuminate\Http\Client\Response;

/** Shared prompt + response handling for OpenAI-compatible vision chat endpoints. */
final class ChatVision
{
    public static function prompt(array $knownConditions): string
    {
        $list = $knownConditions === [] ? '(none provided)' : implode('; ', array_slice($knownConditions, 0, 80));

        return <<<TXT
You are assisting a farmer. Look at this plant photograph and reply with ONLY a JSON object, no prose:
{"crop": "<crop common name or null>", "condition": "<one name from the KNOWN list that best matches, 'healthy' if the plant looks healthy, or 'unknown'>", "is_healthy": <true|false>}
Rules: choose "condition" ONLY from the KNOWN list, or "healthy", or "unknown". Do not invent diseases, treatments, doses or confidence scores. If the image is not a plant, use "unknown".
KNOWN: {$list}
TXT;
    }

    /** @return array{crop: ?string, condition: string, is_healthy: bool} */
    public static function parse(string $content): array
    {
        $json = null;
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $json = json_decode($m[0], true);
        }
        if (! is_array($json) || ! isset($json['condition']) || ! is_string($json['condition'])) {
            // Transient: models occasionally answer in prose; a retry usually fixes it.
            throw new ProviderException('invalid_response', 'The model returned an unusable response.', true);
        }
        $condition = trim(mb_substr($json['condition'], 0, 120));
        $crop = isset($json['crop']) && is_string($json['crop']) && strtolower($json['crop']) !== 'null' ? trim(mb_substr($json['crop'], 0, 80)) : null;
        $healthy = ($json['is_healthy'] ?? null) === true || LabelNormalizer::isHealthy(LabelNormalizer::words($condition));

        return ['crop' => $crop ?: null, 'condition' => $condition === '' ? 'unknown' : $condition, 'is_healthy' => $healthy];
    }

    /** Map an HTTP failure to our error categories. Never includes response bodies (may echo keys or prompts). */
    public static function httpError(Response $r, string $provider): ProviderException
    {
        $s = $r->status();

        return match (true) {
            in_array($s, [415, 422], true) => new ProviderException('invalid_input', "$provider rejected the image.", false, $s, true),
            $s === 400 => new ProviderException('provider_rejected', "$provider rejected the request.", false, $s),
            in_array($s, [401, 403], true) => new ProviderException('auth_error', "$provider credentials were rejected.", false, $s),
            $s === 402 => new ProviderException('payment_required', "$provider requires payment; not used.", false, $s),
            $s === 404 => new ProviderException('model_unavailable', "$provider model or endpoint not found.", false, $s),
            $s === 408 => new ProviderException('timeout', "$provider timed out.", true, $s),
            $s === 429 => new ProviderException('rate_limited', "$provider rate or free-tier limit reached.", true, $s),
            $s >= 500 => new ProviderException('provider_error', "$provider is temporarily unavailable.", true, $s),
            default => new ProviderException('provider_rejected', "$provider returned HTTP $s.", false, $s),
        };
    }
}
