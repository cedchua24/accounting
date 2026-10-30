<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->route('expense_type') ?? $this->route('expenseType');
        $id = is_object($type) ? $type->id : $type;

        return [
            'chart_account_group_id' => [
                'nullable',
                Rule::exists('chart_account_groups', 'id')->where('is_active', true),
            ],
            'chart_account_id' => [
                'nullable',
                Rule::exists('chart_accounts', 'id')->where('type', 'expense')->where('is_active', true),
            ],
            'code' => ['required', 'string', 'max:30', Rule::unique('expense_types', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255', Rule::unique('expense_types', 'name')->ignore($id)],
            'is_profit' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->code))]);
        }
    }
}
