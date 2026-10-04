<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_financial_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'destination_financial_account_id' => [
                'required', 'different:source_financial_account_id',
                Rule::exists('financial_accounts', 'id')->where('is_active', true),
            ],
            'transfer_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'memo' => ['nullable', 'string'],
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
