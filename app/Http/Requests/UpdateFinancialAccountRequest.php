<?php

namespace App\Http\Requests;

use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\FinancialAccountApplicationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var FinancialAccount $account */
        $account = $this->route('financial_account');
        $kind = $this->input('kind', $account->kind);
        $requiresInstitution = $kind !== 'cash'
            && ($this->has('financial_institution_id') || ! $account->financial_institution_id);
        $requiresAccountNumber = $kind !== 'cash'
            && ($this->has('account_number') || ! $account->account_number);
        $isNewCreditCard = $kind === 'credit_card' && $account->kind !== 'credit_card';
        $allowedApplicationIds = array_values(array_unique([
            ...FinancialAccountApplicationType::where('is_active', true)->pluck('id')->all(),
            ...$account->applications()->pluck('application_id')->all(),
        ]));

        return [
            'owner_branch_id' => ['sometimes', 'nullable', Rule::exists('branches', 'id')->where('is_active', true)],
            'is_company_wide' => ['sometimes', 'boolean'],
            'branch_ids' => ['sometimes', 'array', 'distinct'],
            'branch_ids.*' => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
            'financial_institution_id' => [
                Rule::requiredIf($requiresInstitution),
                'nullable',
                'exists:financial_institutions,id',
            ],
            'chart_account_id' => [
                'sometimes',
                'required',
                'exists:chart_accounts,id',
                Rule::unique('financial_accounts')->ignore($account),
            ],
            'kind' => ['sometimes', 'required', Rule::in(['cash', 'bank_account', 'e_wallet', 'checking_account', 'credit_card'])],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'account_number' => [
                Rule::requiredIf($requiresAccountNumber),
                'nullable',
                'string',
                'max:100',
            ],
            'account_holder' => ['sometimes', 'nullable', 'integer', Rule::exists('user_accounts', 'id')->where('is_active', true)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'credit_limit' => [Rule::requiredIf($kind === 'credit_card' && ($isNewCreditCard || $this->has('credit_limit') || $account->credit_limit === null)), 'nullable', 'numeric', 'gte:0'],
            'statement_day' => [Rule::requiredIf($kind === 'credit_card' && ($isNewCreditCard || $this->has('statement_day') || $account->statement_day === null)), 'nullable', 'integer', 'between:1,31'],
            'due_day' => [Rule::requiredIf($kind === 'credit_card' && ($isNewCreditCard || $this->has('due_day') || $account->due_day === null)), 'nullable', 'integer', 'between:1,31'],
            'buffer_days' => ['sometimes', 'integer', 'between:0,365'],
            'usable_for_receipts' => ['sometimes', 'boolean'],
            'usable_for_payments' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'application_ids' => ['sometimes', 'array', 'distinct'],
            // Internal legacy tags may round-trip on their existing account, but
            // cannot be newly assigned to a different account.
            'application_ids.*' => ['required', 'integer', Rule::in($allowedApplicationIds)],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            /** @var FinancialAccount $account */
            $account = $this->route('financial_account');
            $isCompanyWide = $this->has('is_company_wide')
                ? $this->boolean('is_company_wide')
                : $account->is_company_wide;
            $branchIds = $this->has('branch_ids')
                ? collect($this->input('branch_ids', []))->map(fn ($id) => (int) $id)
                : $account->accessibleBranches()->pluck('branches.id');
            $ownerBranchId = $this->input('owner_branch_id', $account->owner_branch_id);

            if ($isCompanyWide && $this->has('branch_ids') && $branchIds->isNotEmpty()) {
                $validator->errors()->add('branch_ids', 'A company-wide account does not need individual branch access.');
            }
            if (! $isCompanyWide && $branchIds->isEmpty()) {
                $validator->errors()->add('branch_ids', 'Select at least one branch for a restricted account.');
            }
            if ($ownerBranchId && ! $isCompanyWide && ! $branchIds->contains((int) $ownerBranchId)) {
                $validator->errors()->add('owner_branch_id', 'The managing branch must also have access to the account.');
            }

            if (! $this->filled('account_number')) {
                return;
            }

            $normalized = strtoupper((string) preg_replace('/\s+/', '', trim((string) $this->input('account_number'))));
            if (FinancialAccount::where('account_number_hash', hash('sha256', $normalized))
                ->whereKeyNot($account->id)
                ->exists()) {
                $validator->errors()->add('account_number', 'This account number already exists.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
        $applications = $this->input('application_ids', $this->input('applications'));
        if (is_array($applications)) {
            $this->merge([
                'application_ids' => array_map(
                    [FinancialAccountApplication::class, 'normalizeId'],
                    $applications
                ),
            ]);
        }
    }
}
