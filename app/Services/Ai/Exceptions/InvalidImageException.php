<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class InvalidImageException extends RuntimeException
{
    public function __construct(string $message = 'The image could not be processed. Please upload a clear JPG, PNG or WEBP photo.')
    {
        parent::__construct($message);
    }
}
