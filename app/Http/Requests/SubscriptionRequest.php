<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubscriptionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'], 'subscription_days' => ['required', 'integer', 'between:1,3660'],
            'breakfast' => ['nullable', 'boolean'], 'lunch' => ['nullable', 'boolean'], 'dinner' => ['nullable', 'boolean'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'], 'renewal_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
    protected function prepareForValidation(): void
    {
        $this->merge(collect(['breakfast', 'lunch', 'dinner'])->mapWithKeys(fn ($meal) => [$meal => $this->boolean($meal)])->all());
    }
}
