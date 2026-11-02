<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'farm_name' => ['nullable', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:150'],
            'farm_size_ha' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'main_crops' => ['nullable', 'string', 'max:190'],
        ];
    }
}
