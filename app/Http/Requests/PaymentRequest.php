<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['transaction_token' => ['required', 'uuid'], 'subscription_id' => ['required', 'exists:subscriptions,id'], 'payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'in:cash,bank,upi,card,other'], 'payment_type' => ['required', 'in:full,partial'], 'confirm_overpayment' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
