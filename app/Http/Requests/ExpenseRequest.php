<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['expense_date' => ['required', 'date'], 'expense_category_id' => ['required', 'exists:expense_categories,id'], 'amount' => ['required', 'numeric', 'gt:0'], 'vendor_name' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:3000'], 'bill' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']];
    }
}
