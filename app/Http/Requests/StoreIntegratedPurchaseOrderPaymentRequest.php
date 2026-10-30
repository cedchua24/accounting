<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIntegratedPurchaseOrderPaymentRequest extends FormRequest
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
            'external_supplier_id' => ['required', 'integer', 'min:1'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'external_order_id' => ['required', 'integer', 'min:1'],
            'order_number' => ['nullable', 'string', 'max:50'],
            'order_date' => ['required', 'date'],
            'order_total' => ['required', 'numeric', 'gt:0'],
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'installment_count' => ['sometimes', 'integer', 'between:1,60'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
    }
}
