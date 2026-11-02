<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind the `admin` middleware
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::in(['farmer', 'admin'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
