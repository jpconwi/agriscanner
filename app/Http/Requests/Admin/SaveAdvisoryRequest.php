<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveAdvisoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** An unchecked HTML checkbox sends nothing; treat that as false. */
    protected function prepareForValidation(): void
    {
        $this->merge(['is_published' => $this->boolean('is_published')]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:80'],
            'summary' => ['nullable', 'string', 'max:600'],
            'body' => ['required', 'string', 'max:60000'],
            'is_published' => ['required', 'boolean'],
        ];
    }
}
