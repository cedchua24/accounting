<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'original_payment_id' => ['required', 'exists:payments,id'],
            'financial_account_id' => [
                'required',
                Rule::exists('financial_accounts', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->where('usable_for_payments', true)
                    ->whereIn('kind', ['cash', 'bank_account', 'checking_account', 'e_wallet'])),
            ],
            'payment_method_id' => [
                'required',
                Rule::exists('payment_methods', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->where('creates_obligation', false)
                    ->whereIn('type', ['cash', 'bank_transfer', 'e_wallet'])),
            ],
            'refund_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],
            'post' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->input('idempotency_key', $this->header('Idempotency-Key')),
        ]);

        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
    }
}
