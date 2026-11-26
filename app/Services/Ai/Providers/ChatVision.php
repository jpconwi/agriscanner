<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiGuidance;
use App\Services\Ai\Exceptions\ProviderException;
use App\Support\LabelNormalizer;
use Illuminate\Http\Client\Response;

/** Shared prompt + response handling for OpenAI-compatible vision chat endpoints. */
final class ChatVision
{
    /** Room for the JSON answer with its guidance lists; the old 200 would cut the reply off mid-object. */
    public const MAX_TOKENS = 900;

    public static function prompt(array $knownConditions): string
    {
        $list = $knownConditions === [] ? '(none provided)' : implode('; ', array_slice($knownConditions, 0, 80));

        return <<<TXT
You are an experienced plant pathologist helping a farmer. Look at the plant photograph, identify the crop yourself, and reply with ONLY one JSON object (no prose, no markdown):
{"crop": "<common name of the crop or plant, or null>", "condition": "<disease or disorder name, 'healthy', or 'unknown'>", "is_healthy": <true|false>, "summary": "<1-2 sentences: what the problem is and what you can see in the photo>", "symptoms": ["<up to 5 visible signs>"], "causes": ["<up to 4 likely causes or favouring conditions>"], "management": ["<up to 5 safe, general actions>"], "prevention": ["<up to 4 habits>"], "follow_up": "<safe monitoring advice and when to seek local help>"}
Rules:
- Work out the crop from the photo. Any crop or plant is allowed.
- For "condition", use the exact name from the KNOWN list when one matches. Otherwise give the most likely common disease or disorder name. If you cannot tell, use "unknown" and still describe what is visible in "symptoms".
- Describe only what you can see. If you are unsure, say so in "summary". Never give confidence scores.
- "management" is general, practical advice only (for example removing infected leaves, spacing and airflow, drainage, crop rotation, clean tools, resistant varieties, asking a local agricultural technician). Do NOT name any pesticide, fungicide or active ingredient, and give NO doses, rates, mixing amounts or spray schedules.
- "follow_up" explains how to monitor the crop and when to ask a local agricultural technician for help. Do NOT give a precise treatment schedule.
- If the plant looks healthy: condition "healthy", is_healthy true, and a short general care tip.
- If the photo shows no plant: crop null, condition "unknown", is_healthy false, empty lists.
- Keep every item under 160 characters.
KNOWN: {$list}
TXT;
    }

    /** @return array{crop: ?string, condition: string, is_healthy: bool, details: ?array} */
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

        return [
            'crop' => $crop ?: null,
            'condition' => $condition === '' ? 'unknown' : $condition,
            'is_healthy' => $healthy,
            'details' => AiGuidance::sanitize($json),
        ];
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
