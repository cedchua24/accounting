<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIntegratedExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:50'],
            'external_branch_id' => ['required', 'integer', 'min:1'],
            'branch_name' => ['required', 'string', 'max:255'],
            'external_expense_id' => ['required', 'integer', 'min:1'],
            'external_chart_account_id' => ['required', 'integer', 'min:1'],
            'chart_account_name' => ['required', 'string', 'max:255'],
            'expense_name' => ['required', 'string', 'max:255'],
            'expense_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string'],
            'currency' => ['required', 'string', 'size:3'],
            'record_payment' => ['required', 'boolean'],
            'financial_account_id' => ['required_if:record_payment,true', 'nullable', 'integer', 'exists:financial_accounts,id'],
            'payment_method_id' => ['required_if:record_payment,true', 'nullable', 'integer', 'exists:payment_methods,id'],
            'payment_date' => ['required_if:record_payment,true', 'nullable', 'date'],
            'installment_count' => ['sometimes', 'integer', 'between:1,60'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required_if:record_payment,true', 'nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
    }
}
