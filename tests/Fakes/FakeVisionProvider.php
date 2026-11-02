<?php

namespace Tests\Fakes;

use App\Services\Ai\Contracts\VisionProvider;
use App\Services\Ai\Dto\AnalysisResult;
use App\Services\Ai\Dto\ImagePayload;
use App\Services\Ai\Exceptions\ProviderException;

/** Scripted provider: each call consumes the next outcome (an AnalysisResult to return or a ProviderException to throw). */
class FakeVisionProvider implements VisionProvider
{
    public int $calls = 0;

    /** @param list<AnalysisResult|ProviderException> $script */
    public function __construct(private readonly string $name, public array $script = [], public bool $configured = true)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function model(): ?string
    {
        return 'fake-model';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function analyze(ImagePayload $image, array $knownConditions = []): AnalysisResult
    {
        $this->calls++;
        $next = array_shift($this->script);
        if ($next instanceof ProviderException) {
            throw $next;
        }

        return $next ?? throw new \LogicException('FakeVisionProvider script exhausted');
    }

    public function verify(): array
    {
        return ['ok' => $this->configured, 'status' => $this->configured ? 'healthy' : 'unconfigured', 'message' => 'fake'];
    }

    public static function result(string $provider = 'huggingface', string $condition = 'early blight', bool $healthy = false, ?float $confidence = 0.91, ?string $crop = 'tomato'): AnalysisResult
    {
        return new AnalysisResult($provider, 'fake-model', $crop, $condition, $healthy, $confidence, [['label' => 'Tomato___Early_blight', 'score' => $confidence]]);
    }

    public static function transient(string $category = 'provider_error', int $status = 503): ProviderException
    {
        return new ProviderException($category, 'simulated '.$category, true, $status);
    }

    public static function permanent(string $category = 'auth_error', int $status = 401): ProviderException
    {
        return new ProviderException($category, 'simulated '.$category, false, $status);
    }

    public static function invalidInput(): ProviderException
    {
        return new ProviderException('invalid_input', 'bad image', false, 422, true);
    }
}
