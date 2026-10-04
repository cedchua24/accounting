<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->route('branch')?->id;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('branches')->ignore($id)],
            'name' => ['required', 'string', 'max:255', Rule::unique('branches')->ignore($id)],
            'inventory_account_id' => ['required', 'different:due_from_account_id', 'different:due_to_account_id', Rule::exists('chart_accounts', 'id')->where('type', 'asset')],
            'due_from_account_id' => ['required', 'different:inventory_account_id', 'different:due_to_account_id', Rule::exists('chart_accounts', 'id')->where('type', 'asset')],
            'due_to_account_id' => ['required', 'different:inventory_account_id', 'different:due_from_account_id', Rule::exists('chart_accounts', 'id')->where('type', 'liability')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
