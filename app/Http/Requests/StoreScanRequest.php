<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * First-line checks only (type by extension/MIME, size). The real content check (magic bytes,
 * decode, dimensions) runs in ImageInspector so a renamed file cannot slip through.
 */
class StoreScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Allow the key as a header too.
        if (! $this->filled('idempotency_key') && $this->header('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:'.implode(',', config('agriscan.uploads.mimes')), 'mimetypes:'.implode(',', config('agriscan.uploads.allowed_mime_types')), 'max:'.config('agriscan.uploads.max_kb')],
            'crop_id' => ['nullable', 'integer', 'exists:crops,id'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }
}
