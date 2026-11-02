<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Dto\AnalysisResult;
use App\Services\Ai\Dto\ImagePayload;
use App\Services\Ai\Exceptions\ProviderException;

interface VisionProvider
{
    public function name(): string;

    public function model(): ?string;

    /** Enabled, credentials present, model set, and (for paid-capable providers) explicitly authorised. */
    public function isConfigured(): bool;

    /** @param list<string> $knownConditions disease names from the reference library, to constrain chat models */
    public function analyze(ImagePayload $image, array $knownConditions = []): AnalysisResult;

    /** Verifies the endpoint is reachable and the model accepts image input. Never sends user data. */
    public function verify(): array;

}
