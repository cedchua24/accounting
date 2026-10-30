<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $item = $this->route('expense_item') ?? $this->route('expenseItem');
        $id = is_object($item) ? $item->id : $item;

        return [
            'expense_category_id' => [
                'required',
                Rule::exists('expense_categories', 'id')->where('is_active', true),
            ],
            'chart_account_id' => [
                'required',
                Rule::exists('chart_accounts', 'id')
                    ->where('type', 'expense')
                    ->where('is_active', true)
                    ->where('is_postable', true),
            ],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('expense_items', 'code')
                    ->where(fn ($query) => $query->where('expense_category_id', $this->integer('expense_category_id')))
                    ->ignore($id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string'],
            'is_hidden' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'system_key' => ['nullable', 'string', 'max:80', Rule::unique('expense_items', 'system_key')->ignore($id)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        if ($this->has('code')) {
            $values['code'] = strtoupper(trim((string) $this->code));
        }
        if ($this->has('system_key')) {
            $value = strtoupper(trim((string) $this->system_key));
            $values['system_key'] = $value === '' ? null : $value;
        }
        if ($values !== []) {
            $this->merge($values);
        }
    }
}
