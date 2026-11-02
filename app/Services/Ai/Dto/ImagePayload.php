<?php

namespace App\Services\Ai\Dto;

final class ImagePayload
{
    public function __construct(public readonly string $bytes, public readonly string $mime)
    {
    }

    public function dataUri(): string
    {
        return 'data:'.$this->mime.';base64,'.base64_encode($this->bytes);
    }
}
