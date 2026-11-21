<?php

namespace App\Services\Ai\Dto;

/** Normalised model output. confidence is null unless the model returned a genuine probability. */
final class AnalysisResult
{
    /** @param list<array{label: string, score: ?float}> $alternatives */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $cropLabel,
        public readonly string $conditionLabel,
        public readonly bool $isHealthy,
        public readonly ?float $confidence,
        public readonly array $alternatives = [],
        public readonly ?int $latencyMs = null,
        /** @var ?array{summary: ?string, symptoms: list<string>, causes: list<string>, management: list<string>, prevention: list<string>} AI-written guidance, already sanitised; null for classifiers */
        public readonly ?array $details = null,
    ) {
    }

    public function withLatency(int $ms): self
    {
        return new self($this->provider, $this->model, $this->cropLabel, $this->conditionLabel, $this->isHealthy, $this->confidence, $this->alternatives, $ms, $this->details);
    }
}
