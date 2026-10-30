<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetAccountAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'adjustment_account_id' => ['required', 'integer', Rule::exists('chart_accounts', 'id')->where('is_active', true)],
            'actual_balance' => ['required', 'numeric', 'gte:0'],
            'adjustment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
