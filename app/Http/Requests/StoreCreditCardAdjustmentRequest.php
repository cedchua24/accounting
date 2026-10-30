<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditCardAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_obligation_id' => ['required', 'integer', 'exists:payment_obligations,id'],
            'adjustment_account_id' => ['required', 'integer', Rule::exists('chart_accounts', 'id')->where('is_active', true)],
            'actual_amount_due' => ['required', 'numeric', 'gte:0'],
            'adjustment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
