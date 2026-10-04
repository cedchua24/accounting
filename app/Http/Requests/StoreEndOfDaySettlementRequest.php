<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEndOfDaySettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['required', 'integer', 'distinct', Rule::exists('branches', 'id')->where('is_active', true)],
            'business_date' => ['required', 'date'],
            'destination_financial_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'currency' => ['required', 'string', 'size:3'],
            'memo' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
    }
}
