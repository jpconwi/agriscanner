<?php

namespace App\Services\Storage\Exceptions;

use RuntimeException;

/** Every configured store failed (or none is configured). Shown to the user as a retryable 503. */
class StorageUnavailableException extends RuntimeException
{
    public static function noneConfigured(): self
    {
        return new self('Image storage is not available right now. Your photo was not saved. Please try again later.');
    }

    public static function allFailed(): self
    {
        return new self('We could not store your photo safely right now, so nothing was saved. Please try again in a few minutes.');
    }
}
