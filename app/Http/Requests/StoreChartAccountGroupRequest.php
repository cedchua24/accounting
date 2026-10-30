<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChartAccountGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $group = $this->route('chart_account_group') ?? $this->route('chartAccountGroup');
        $id = is_object($group) ? $group->id : $group;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('chart_account_groups', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255', Rule::unique('chart_account_groups', 'name')->ignore($id)],
            'normal_balance' => ['required', Rule::in(['debit', 'credit'])],
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
