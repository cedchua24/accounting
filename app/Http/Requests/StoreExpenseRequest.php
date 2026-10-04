<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
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
        $id = $this->route('expense')?->id;

        return [
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
            'number' => ['required', 'string', 'max:50', Rule::unique('expenses')->ignore($id)],
            'payee_id' => ['nullable', 'exists:counterparties,id'],
            'chart_account_id' => ['required', Rule::exists('chart_accounts', 'id')->where('type', 'expense')],
            'expense_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'status' => ['sometimes', Rule::in(['unpaid', 'partially_paid', 'paid', 'cancelled'])],
            'description' => ['required', 'string'],
        ];
    }
}
