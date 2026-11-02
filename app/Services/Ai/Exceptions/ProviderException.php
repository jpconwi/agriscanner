<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * A single provider call failed.
 *  transient  = retrying the SAME provider may help (timeouts, 5xx, rate limits).
 *  terminal   = the input itself is bad; neither retrying nor switching providers will help.
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly bool $transient = false,
        public readonly ?int $httpStatus = null,
        public readonly bool $terminal = false,
    ) {
        parent::__construct($message);
    }
}
