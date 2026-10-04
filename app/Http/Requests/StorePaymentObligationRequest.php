<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentObligationRequest extends FormRequest
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
        $id = $this->route('payment_obligation')?->id;

        return [
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('is_active', true)],
            'number' => ['required', 'string', 'max:60', Rule::unique('payment_obligations')->ignore($id)],
            'type' => ['required', Rule::in(['credit_card_statement', 'issued_cheque', 'purchase_order', 'expense', 'ibo', 'other'])],
            'financial_account_id' => ['nullable', 'exists:financial_accounts,id'],
            'counterparty_id' => ['nullable', 'exists:counterparties,id'],
            'offset_account_id' => ['nullable', 'exists:chart_accounts,id'],
            'statement_date' => ['nullable', 'date'],
            'due_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'principal_amount' => ['required', 'numeric', 'gt:0'],
            'interest_amount' => ['sometimes', 'numeric', 'gte:0'],
            'fee_amount' => ['sometimes', 'numeric', 'gte:0'],
            'minimum_due' => ['sometimes', 'numeric', 'gte:0'],
            'status' => ['sometimes', Rule::in(['open', 'partially_paid', 'paid', 'overdue', 'voided'])],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $account = \App\Models\FinancialAccount::find($this->financial_account_id);
            if ($this->type === 'credit_card_statement' && $account?->kind !== 'credit_card') {
                $validator->errors()->add('financial_account_id', 'A credit-card statement requires a credit-card account.');
            }
            if ($account && strtoupper((string) $this->currency) !== $account->currency) {
                $validator->errors()->add('currency', 'The obligation currency must match the financial account.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
    }
}
