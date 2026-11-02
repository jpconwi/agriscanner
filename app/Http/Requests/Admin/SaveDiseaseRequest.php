<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDiseaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** HTML forms send textareas as one string with a line per item. */
    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['symptoms', 'contributing_factors', 'treatments', 'prevention'] as $field) {
            $v = $this->input($field);
            if (is_string($v)) {
                $merge[$field] = array_values(array_filter(array_map('trim', preg_split('/\R/', $v) ?: []), fn ($x) => $x !== ''));
            }
        }
        if ($this->input('crop_id') === '') {
            $merge['crop_id'] = null;
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $list = ['nullable', 'array', 'max:30'];

        return [
            'crop_id' => ['nullable', 'integer', 'exists:crops,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:4000'],
            'severity' => ['required', Rule::in(['low', 'moderate', 'high'])],
            'follow_up' => ['nullable', 'string', 'max:2000'],
            'symptoms' => $list, 'symptoms.*' => ['string', 'max:500'],
            'contributing_factors' => $list, 'contributing_factors.*' => ['string', 'max:500'],
            'treatments' => $list, 'treatments.*' => ['string', 'max:800'],
            'prevention' => $list, 'prevention.*' => ['string', 'max:800'],
        ];
    }
}
