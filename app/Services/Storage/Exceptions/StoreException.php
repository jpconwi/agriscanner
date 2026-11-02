<?php

namespace App\Services\Storage\Exceptions;

use RuntimeException;

/** A single store failed. $transient = worth retrying with backoff. */
class StoreException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = true, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
