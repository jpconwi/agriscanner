<?php

namespace App\Services\Scans;

use App\Services\Ai\Exceptions\InvalidImageException;
use Illuminate\Http\UploadedFile;

/**
 * Validates the ACTUAL bytes of an upload (not the client-supplied name or MIME type):
 * real MIME via libmagic, decodable image header, sane dimensions, and (when GD is present) a full decode.
 */
class ImageInspector
{
    /** @return array{bytes: string, mime: string, extension: string, width: int, height: int, sha256: string, size: int} */
    public function inspect(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $bytes = $path ? @file_get_contents($path) : false;
        if ($bytes === false || $bytes === '') {
            throw new InvalidImageException('The uploaded file is empty or unreadable.');
        }

        $maxBytes = (int) config('agriscan.uploads.max_kb') * 1024;
        if (strlen($bytes) > $maxBytes) {
            throw new InvalidImageException('The image is larger than the '.round($maxBytes / 1048576, 1).' MB limit.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! in_array($mime, config('agriscan.uploads.allowed_mime_types'), true)) {
            throw new InvalidImageException('Only JPG, PNG and WEBP images are accepted.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ($info['mime'] ?? null) !== $mime) {
            throw new InvalidImageException('The file is not a valid image.');
        }
        [$w, $h] = $info;
        $min = (int) config('agriscan.uploads.min_dimension');
        $max = (int) config('agriscan.uploads.max_dimension');
        if ($w < $min || $h < $min) {
            throw new InvalidImageException("The image is too small. It must be at least {$min}×{$min} pixels.");
        }
        if ($w > $max || $h > $max) {
            throw new InvalidImageException("The image is too large. The maximum is {$max}×{$max} pixels.");
        }

        // Full decode catches truncated files and polyglots that only have a valid header.
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($bytes);
            if ($img === false) {
                throw new InvalidImageException('The image appears to be corrupted.');
            }
            unset($img);
        }

        return [
            'bytes' => $bytes,
            'mime' => $mime,
            'extension' => match ($mime) {'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'},
            'width' => $w,
            'height' => $h,
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
        ];
    }
}
