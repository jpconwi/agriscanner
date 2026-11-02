<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\VisionProvider;
use App\Services\Ai\Dto\AnalysisResult;
use App\Services\Ai\Dto\ImagePayload;
use App\Services\Ai\Exceptions\ProviderException;
use App\Support\LabelNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Hugging Face Inference.
 *  task=image-classification : raw image bytes -> [{label, score}]. Scores are real class probabilities.
 *  task=chat                 : a vision-language model through the OpenAI-compatible router. No confidence is reported.
 * Free access is subject to Hugging Face's own monthly limits; a 402/429 stops use instead of paying.
 */
class HuggingFaceProvider implements VisionProvider
{
    public function __construct(private readonly array $config, private readonly int $timeout = 45)
    {
    }

    public function name(): string
    {
        return 'huggingface';
    }

    public function model(): ?string
    {
        return $this->config['model'] ?: null;
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false) && filled($this->config['token'] ?? null) && filled($this->config['model'] ?? null);
    }

    private function task(): string
    {
        return $this->config['task'] === 'chat' ? 'chat' : 'image-classification';
    }

    public function analyze(ImagePayload $image, array $knownConditions = []): AnalysisResult
    {
        return $this->task() === 'chat' ? $this->viaChat($image, $knownConditions) : $this->viaClassification($image);
    }

    private function viaClassification(ImagePayload $image): AnalysisResult
    {
        $url = rtrim($this->config['classification_base'], '/').'/'.$this->config['model'];
        try {
            $response = Http::withToken($this->config['token'])
                ->acceptJson()
                ->connectTimeout(10)->timeout($this->timeout)
                ->withBody($image->bytes, $image->mime)
                ->post($url);
        } catch (ConnectionException $e) {
            throw new ProviderException('timeout', 'Hugging Face did not respond in time.', true);
        }
        if (! $response->successful()) {
            throw ChatVision::httpError($response, 'Hugging Face');
        }

        $rows = $response->json();
        // Some deployments wrap results: [[{label,score}...]]
        if (isset($rows[0]) && is_array($rows[0]) && isset($rows[0][0])) {
            $rows = $rows[0];
        }
        $rows = array_values(array_filter(is_array($rows) ? $rows : [], fn ($r) => is_array($r) && isset($r['label']) && is_string($r['label']) && isset($r['score']) && is_numeric($r['score'])));
        if ($rows === []) {
            throw new ProviderException('invalid_response', 'Hugging Face returned no usable classes.', true);
        }
        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);

        [$crop, $condition] = LabelNormalizer::split($rows[0]['label']);
        $alts = array_map(fn ($r) => ['label' => $r['label'], 'score' => round((float) $r['score'], 5)], array_slice($rows, 0, 5));

        return new AnalysisResult(
            'huggingface', $this->config['model'], $crop, $condition,
            LabelNormalizer::isHealthy($condition), max(0.0, min(1.0, (float) $rows[0]['score'])), $alts,
        );
    }

    private function viaChat(ImagePayload $image, array $known): AnalysisResult
    {
        try {
            $response = Http::withToken($this->config['token'])
                ->acceptJson()->asJson()
                ->connectTimeout(10)->timeout($this->timeout)
                ->post($this->config['chat_url'], [
                    'model' => $this->config['model'],
                    'temperature' => 0,
                    'max_tokens' => 200,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => ChatVision::prompt($known)],
                            ['type' => 'image_url', 'image_url' => ['url' => $image->dataUri()]],
                        ],
                    ]],
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderException('timeout', 'Hugging Face did not respond in time.', true);
        }
        if (! $response->successful()) {
            throw ChatVision::httpError($response, 'Hugging Face');
        }
        $parsed = ChatVision::parse((string) $response->json('choices.0.message.content', ''));

        // Chat models do not provide calibrated probabilities, so confidence stays null.
        return new AnalysisResult('huggingface', $this->config['model'], $parsed['crop'], $parsed['condition'], $parsed['is_healthy'], null);
    }

    public function verify(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'status' => 'unconfigured', 'message' => 'Set HUGGINGFACE_API_TOKEN and HUGGINGFACE_MODEL.'];
        }
        try {
            $r = Http::withToken($this->config['token'])->acceptJson()->timeout(15)
                ->get(rtrim($this->config['hub_url'], '/').'/'.$this->config['model']);
        } catch (ConnectionException) {
            return ['ok' => false, 'status' => 'down', 'message' => 'Could not reach the Hugging Face Hub.'];
        }
        if ($r->status() === 404) {
            return ['ok' => false, 'status' => 'down', 'message' => 'Model not found on the Hub.'];
        }
        if (! $r->successful()) {
            return ['ok' => false, 'status' => 'degraded', 'message' => 'Hub returned HTTP '.$r->status().'.'];
        }
        $tag = (string) $r->json('pipeline_tag', '');
        $imageTags = ['image-classification', 'image-text-to-text', 'visual-question-answering', 'image-to-text'];
        if (! in_array($tag, $imageTags, true)) {
            return ['ok' => false, 'status' => 'degraded', 'message' => "Model pipeline \"$tag\" does not accept image input."];
        }
        $expected = $this->task() === 'chat' ? ['image-text-to-text', 'visual-question-answering', 'image-to-text'] : ['image-classification'];
        if (! in_array($tag, $expected, true)) {
            return ['ok' => false, 'status' => 'degraded', 'message' => "Model pipeline \"$tag\" does not match HUGGINGFACE_TASK={$this->task()}."];
        }

        return ['ok' => true, 'status' => 'healthy', 'message' => "Model found ($tag). Availability for free inference is confirmed by the first real call; limits are set by Hugging Face."];
    }
}
