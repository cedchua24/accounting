<?php

namespace App\Http\Requests;

use App\Models\ExpenseItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'expense_item_id' => [
                'nullable',
                Rule::exists('expense_items', 'id')->where('is_active', 1)->where('is_hidden', 0),
            ],
            'chart_account_id' => [
                'required_without:expense_item_id',
                'nullable',
                Rule::exists('chart_accounts', 'id')
                    ->where('type', 'expense')
                    ->where('is_active', true)
                    ->where('is_postable', true),
            ],
            'expense_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'status' => ['sometimes', Rule::in(['unpaid', 'partially_paid', 'paid', 'cancelled'])],
            'approval_status' => ['sometimes', Rule::in(['draft', 'pending', 'approved', 'rejected'])],
            'approval_notes' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:100'],
            'is_received' => ['sometimes', 'boolean'],
            'received_date' => ['nullable', 'date'],
            'description' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $itemId = $this->input('expense_item_id');
            if (! $itemId) {
                return;
            }
            $item = ExpenseItem::find($itemId);
            if (! $item) {
                return;
            }
            $submittedAccountId = $this->input('chart_account_id');
            if ($submittedAccountId && (int) $submittedAccountId !== (int) $item->chart_account_id) {
                $validator->errors()->add(
                    'chart_account_id',
                    'The chart account must match the selected expense item.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
    }
}
