<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'gender' => ['required', 'in:male,female,other'],
            'age' => ['nullable', 'integer', 'between:1,120'], 'place' => ['nullable', 'string', 'max:255'],
            'primary_mobile' => ['required', 'string', 'max:20'], 'secondary_mobile' => ['nullable', 'string', 'max:20'],
            'primary_address' => ['required', 'string', 'max:2000'], 'secondary_address' => ['nullable', 'string', 'max:2000'],
            'landmark' => ['nullable', 'string', 'max:255'], 'google_map_url' => ['nullable', 'url', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'], 'food_instructions' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
