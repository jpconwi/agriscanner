<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The admin form edits the default quota in MB; the API takes bytes. */
    protected function prepareForValidation(): void
    {
        if ($this->filled('default_quota_mb') && ! $this->has('default_quota_bytes')) {
            $this->merge(['default_quota_bytes' => (int) round((float) $this->input('default_quota_mb') * 1048576)]);
        }
    }

    public function rules(): array
    {
        return [
            'default_quota_bytes' => ['required', 'integer', 'min:1048576', 'max:1099511627776'],
            'warn_percent' => ['required', 'integer', 'between:1,100'],
            'critical_percent' => ['required', 'integer', 'between:1,100', 'gte:warn_percent'],
            'db_warn_percent' => ['required', 'integer', 'between:1,100'],
        ];
    }
}
