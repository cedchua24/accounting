<?php

namespace App\Http\Requests;

use App\Models\FinancialAccountApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
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
        $id = $this->route('financial_account')?->id;

        return [
            'owner_branch_id' => ['nullable', Rule::exists('branches', 'id')->where('is_active', true)],
            'is_company_wide' => ['required', 'boolean'],
            'branch_ids' => ['sometimes', 'array', 'distinct'],
            'branch_ids.*' => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
            'financial_institution_id' => [Rule::requiredIf($this->kind !== 'cash'), 'nullable', 'exists:financial_institutions,id'],
            'chart_account_id' => ['required', 'exists:chart_accounts,id', Rule::unique('financial_accounts')->ignore($id)],
            'kind' => ['required', Rule::in(['cash', 'bank_account', 'e_wallet', 'checking_account', 'credit_card'])],
            'name' => ['required', 'string', 'max:255'],
            'account_number' => [Rule::requiredIf($this->kind !== 'cash'), 'nullable', 'string', 'max:100'],
            'account_holder' => ['nullable', 'integer', Rule::exists('user_accounts', 'id')->where('is_active', true)],
            'description' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'credit_limit' => [Rule::requiredIf($this->kind === 'credit_card'), 'nullable', 'numeric', 'gte:0'],
            'statement_day' => [Rule::requiredIf($this->kind === 'credit_card'), 'nullable', 'integer', 'between:1,31'],
            'due_day' => [Rule::requiredIf($this->kind === 'credit_card'), 'nullable', 'integer', 'between:1,31'],
            'buffer_days' => ['sometimes', 'integer', 'between:0,365'],
            'usable_for_receipts' => ['sometimes', 'boolean'],
            'usable_for_payments' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'application_ids' => ['sometimes', 'array', 'distinct'],
            'application_ids.*' => [
                'required',
                'integer',
                Rule::exists('financial_account_application_types', 'id')->where('is_active', true),
            ],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $branchIds = collect($this->input('branch_ids', []))->map(fn ($id) => (int) $id);
            if ($this->boolean('is_company_wide') && $branchIds->isNotEmpty()) {
                $validator->errors()->add('branch_ids', 'A company-wide account does not need individual branch access.');
            }
            if (! $this->boolean('is_company_wide') && $branchIds->isEmpty()) {
                $validator->errors()->add('branch_ids', 'Select at least one branch for a restricted account.');
            }
            if ($this->owner_branch_id && ! $this->boolean('is_company_wide') && ! $branchIds->contains((int) $this->owner_branch_id)) {
                $validator->errors()->add('owner_branch_id', 'The managing branch must also have access to the account.');
            }

            if (! $this->filled('account_number')) {
                return;
            }
            $hash = hash('sha256', strtoupper((string) preg_replace('/\s+/', '', trim($this->account_number))));
            $duplicate = \App\Models\FinancialAccount::where('account_number_hash', $hash)
                ->when($this->route('financial_account'), fn ($query, $account) => $query->whereKeyNot($account->id))
                ->exists();
            if ($duplicate) {
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
