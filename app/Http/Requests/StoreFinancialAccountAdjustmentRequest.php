<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'financial_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'payment_obligation_id' => ['nullable', 'exists:payment_obligations,id'],
            'adjustment_account_id' => ['required', Rule::exists('chart_accounts', 'id')->where('is_active', true)],
            'adjustment_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'actual_balance' => ['required', 'numeric'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
            'post' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
    }
}
