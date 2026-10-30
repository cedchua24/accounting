<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $loan = $this->route('loan');

        return [
            'number' => ['sometimes', 'string', 'max:60', Rule::unique('loans')->ignore($loan?->id)],
            'direction' => ['required', Rule::in(['borrowed', 'lent'])],
            'counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'borrower_type' => ['sometimes', Rule::in(['company', 'bank', 'credit_card'])],
            'financial_institution_id' => [
                'nullable',
                'integer',
                Rule::exists('financial_institutions', 'id')
                    ->where('type', 'bank')
                    ->where('is_active', true),
            ],
            'user_account_id' => [
                'nullable',
                'integer',
                Rule::exists('user_accounts', 'id')->where('is_active', true),
            ],
            'financial_account_id' => [
                'nullable',
                'required_if:borrower_type,credit_card',
                'integer',
                'exists:financial_accounts,id',
            ],
            'start_date' => ['required', 'date'],
            'first_due_date' => ['required', 'date', 'after_or_equal:start_date'],
            'currency' => ['required', 'string', 'size:3'],
            'principal_amount' => ['required', 'numeric', 'gt:0'],
            'total_interest_amount' => ['sometimes', 'numeric', 'gte:0'],
            'term_months' => ['required', 'integer', 'min:1', 'max:600'],
            'overdue_interest_rate' => ['sometimes', 'numeric', 'gte:0', 'lte:100'],
            'overdue_interest_period' => ['sometimes', Rule::in(['daily', 'monthly', 'annual'])],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $account = \App\Models\FinancialAccount::find($this->financial_account_id);
            $borrowerType = $this->borrower_type ?: 'company';
            if ($borrowerType === 'bank' && ! $this->financial_institution_id) {
                $validator->errors()->add('financial_institution_id', 'Select the lending financial institution.');
            }
            if ($borrowerType === 'bank' && ! $this->user_account_id) {
                $validator->errors()->add('user_account_id', 'Select the bank loan user account.');
            }
            if ($borrowerType === 'company' && ! $this->counterparty_id && ! trim((string) $this->party_name)) {
                $validator->errors()->add('party_name', 'Enter a company name or select an existing counterparty.');
            }
            if ($account && (! $account->is_active || ! in_array($account->kind, ['cash', 'bank_account', 'checking_account', 'credit_card'], true))) {
                $validator->errors()->add('financial_account_id', 'A loan must use a cash, bank, checking, or credit-card account.');
            }
            if ($account && strtoupper((string) $this->currency) !== $account->currency) {
                $validator->errors()->add('currency', 'The loan currency must match the financial account.');
            }
            $compatibleKinds = match ($borrowerType) {
                'credit_card' => ['credit_card'],
                default => null,
            };
            if ($account && $compatibleKinds !== null && ! in_array($account->kind, $compatibleKinds, true)) {
                $validator->errors()->add(
                    'financial_account_id',
                    "The selected internal account is not compatible with borrower_type {$borrowerType}."
                );
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $prepared = [];
        if ($this->has('currency')) {
            $prepared['currency'] = strtoupper((string) $this->currency);
        }
        if ($this->borrower_type === 'bank' && ! $this->has('direction')) {
            $prepared['direction'] = 'borrowed';
        }
        if (! $this->has('start_date')) {
            $prepared['start_date'] = today()->toDateString();
        }
        if ($this->has('total_interest') && ! $this->has('total_interest_amount')) {
            $prepared['total_interest_amount'] = $this->input('total_interest');
        }

        $this->merge($prepared);
    }
}
