<?php

namespace App\Http\Requests;

use App\Models\FinancialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_financial_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'destination_type' => ['required', Rule::in(['internal', 'external'])],
            'destination_financial_account_id' => [
                'nullable', 'required_if:destination_type,internal', 'prohibited_if:destination_type,external',
                'different:source_financial_account_id',
                Rule::exists('financial_accounts', 'id')->where('is_active', true),
            ],
            'transfer_method' => ['required', Rule::in(['cash', 'online', 'e_wallet', 'cheque'])],
            'counterparty_id' => ['nullable', 'exists:counterparties,id'],
            'offset_account_id' => [
                'nullable', 'required_if:destination_type,external', 'prohibited_if:destination_type,internal',
                Rule::exists('chart_accounts', 'id')->where('is_active', true),
            ],
            'recipient_name' => ['nullable', 'required_if:destination_type,external', 'string', 'max:255'],
            'recipient_type' => ['nullable', Rule::in(['individual', 'business'])],
            'external_institution_name' => [
                'nullable',
                Rule::requiredIf(fn () => $this->destination_type === 'external'
                    && in_array($this->transfer_method, ['online', 'e_wallet'], true)),
                'string', 'max:255',
            ],
            'external_account_name' => ['nullable', 'string', 'max:255'],
            'external_account_number' => [
                'nullable',
                Rule::requiredIf(fn () => $this->destination_type === 'external'
                    && in_array($this->transfer_method, ['online', 'e_wallet'], true)),
                'string', 'max:255',
            ],
            'recipient_contact' => ['nullable', 'string', 'max:100'],
            'cheque_number' => ['nullable', 'required_if:transfer_method,cheque', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'uuid', 'unique:financial_account_transfers,idempotency_key'],
            'transfer_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'memo' => ['nullable', 'string'],
            'post' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $source = FinancialAccount::find($this->input('source_financial_account_id'));
        $inferredMethod = match ($source?->kind) {
            'cash' => 'cash',
            'e_wallet' => 'e_wallet',
            default => 'online',
        };

        $this->merge([
            'destination_type' => $this->input('destination_type', 'internal'),
            'transfer_method' => $this->input('transfer_method', $inferredMethod),
            'idempotency_key' => $this->input('idempotency_key', $this->header('Idempotency-Key')),
        ]);

        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
    }
}
