<?php

namespace App\Services\Quota;

use RuntimeException;

class QuotaExceededException extends RuntimeException
{
    public static function full(): self
    {
        return new self('Your storage is full. Delete some scans to free space before uploading more. Nothing has been deleted automatically.');
    }

    public static function wouldExceed(): self
    {
        return new self('This photo would exceed your storage quota. Delete some scans to free space and try again.');
    }
}
