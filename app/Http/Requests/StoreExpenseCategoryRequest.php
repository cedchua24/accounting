<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->route('expense_category') ?? $this->route('expenseCategory');
        $id = is_object($category) ? $category->id : $category;

        return [
            'expense_type_id' => [
                'required',
                Rule::exists('expense_types', 'id')->where('is_active', true),
            ],
            'chart_account_id' => [
                'nullable',
                Rule::exists('chart_accounts', 'id')->where('type', 'expense')->where('is_active', true),
            ],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('expense_categories', 'code')
                    ->where(fn ($query) => $query->where('expense_type_id', $this->integer('expense_type_id')))
                    ->ignore($id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
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
