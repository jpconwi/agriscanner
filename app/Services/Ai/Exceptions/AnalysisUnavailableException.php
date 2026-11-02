<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class AnalysisUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Crop analysis is unavailable right now, so no result was produced. Your photo was not analysed — please try again in a few minutes.')
    {
        parent::__construct($message);
    }
}
