<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
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
        return [
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
            'type' => ['required', Rule::in(['customer', 'purchase_order', 'expense', 'chart_account'])],
            'counterparty_id' => ['nullable', 'exists:counterparties,id'],
            'sales_invoice_id' => [Rule::requiredIf($this->type === 'customer'), 'nullable', 'exists:sales_invoices,id'],
            'purchase_order_id' => [Rule::requiredIf($this->type === 'purchase_order'), 'nullable', 'exists:purchase_orders,id'],
            'expense_id' => [Rule::requiredIf($this->type === 'expense'), 'nullable', 'exists:expenses,id'],
            'offset_account_id' => ['required', Rule::exists('chart_accounts', 'id')->where('is_active', true)],
            'payment_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'idempotency_key' => ['nullable', 'uuid', 'unique:payments,idempotency_key'],
            'memo' => ['nullable', 'string'],
            'post' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.financial_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'lines.*.payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.installment_count' => ['sometimes', 'integer', 'between:1,60'],
            'lines.*.reference' => ['nullable', 'string', 'max:100'],
            'allocations' => ['sometimes', 'array'],
            'allocations.*.payment_obligation_id' => ['required', 'distinct', 'exists:payment_obligations,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $paymentTotal = collect($this->input('lines', []))->sum('amount');
            foreach ($this->input('lines', []) as $index => $line) {
                $account = \App\Models\FinancialAccount::find($line['financial_account_id'] ?? null);
                $method = \App\Models\PaymentMethod::find($line['payment_method_id'] ?? null);
                if ($account && strtoupper((string) $this->currency) !== $account->currency) {
                    $validator->errors()->add("lines.{$index}.financial_account_id", 'The account currency must match the payment currency.');
                }
                if ($account && $this->type === 'customer' && ! $account->usable_for_receipts) {
                    $validator->errors()->add("lines.{$index}.financial_account_id", 'This account is not enabled for receipts.');
                }
                if ($account && $this->type !== 'customer' && ! $account->usable_for_payments) {
                    $validator->errors()->add("lines.{$index}.financial_account_id", 'This account is not enabled for payments.');
                }
                if ($account && ! $account->isAvailableToBranch((int) $this->branch_id)) {
                    $validator->errors()->add("lines.{$index}.financial_account_id", 'This financial account is not available to the selected branch.');
                }
                if ($account?->kind === 'credit_card' && $this->type === 'customer') {
                    $validator->errors()->add("lines.{$index}.financial_account_id", 'A company credit-card account cannot receive a customer payment.');
                }
                if ($account && $method && (($account->kind === 'credit_card') !== ($method->type === 'credit_card'))) {
                    $validator->errors()->add("lines.{$index}.payment_method_id", 'Credit-card accounts must use a credit-card payment method, and vice versa.');
                }
                if (($line['installment_count'] ?? 1) > 1 && $account?->kind !== 'credit_card') {
                    $validator->errors()->add("lines.{$index}.installment_count", 'Installments are only supported for credit-card payment lines.');
                }
                if ($account) {
                    $applications = $account->applications()->with('type')->get()->pluck('application');
                    $requiredApplication = match ($this->type) {
                        'customer' => \App\Models\FinancialAccountApplication::CUSTOMER_RECEIPT,
                        'purchase_order' => \App\Models\FinancialAccountApplication::PURCHASE_ORDER_PAYMENT,
                        'expense' => \App\Models\FinancialAccountApplication::EXPENSE_PAYMENT,
                        default => collect($this->input('allocations', []))->isNotEmpty()
                            ? \App\Models\FinancialAccountApplication::OBLIGATION_PAYMENT
                            : \App\Models\FinancialAccountApplication::DIRECT_PAYMENT,
                    };
                    if ($applications->isNotEmpty() && ! $applications->contains($requiredApplication)) {
                        $validator->errors()->add("lines.{$index}.financial_account_id", "This account is not enabled for {$requiredApplication}.");
                    }
                }
            }

            $allocationTotal = collect($this->input('allocations', []))->sum('amount');
            if ($allocationTotal > $paymentTotal) {
                $validator->errors()->add('allocations', 'Allocated amounts cannot exceed the payment amount.');
            }
            if ($this->type === 'customer' && $allocationTotal > 0) {
                $validator->errors()->add('allocations', 'Payable obligations can only be allocated to outflow payments.');
            }

            $document = match ($this->type) {
                'customer' => \App\Models\SalesInvoice::find($this->sales_invoice_id),
                'purchase_order' => \App\Models\PurchaseOrder::find($this->purchase_order_id),
                'expense' => \App\Models\Expense::find($this->expense_id),
                default => null,
            };
            if ($document) {
                $total = $this->type === 'expense' ? $document->amount : $document->total_amount;
                $paid = $document->payments()->where('status', 'posted')->sum('amount');
                if (strtoupper((string) $this->currency) !== $document->currency) {
                    $validator->errors()->add('currency', 'The payment currency must match the source document currency.');
                }
                if ($paymentTotal > (float) $total - (float) $paid) {
                    $validator->errors()->add('lines', 'The payment total exceeds the unpaid document balance.');
                }
                if (in_array($document->status, ['paid', 'refunded', 'cancelled'])) {
                    $field = match ($this->type) {
                        'customer' => 'sales_invoice_id', 'expense' => 'expense_id', default => 'purchase_order_id'
                    };
                    $validator->errors()->add($field, 'The source document is not payable.');
                }
                if ((int) $document->branch_id !== (int) $this->branch_id) {
                    $validator->errors()->add('branch_id', 'The payment branch must match the source document branch.');
                }
            }
            if ($this->type === 'customer' && $document && $this->counterparty_id && (int) $this->counterparty_id !== (int) $document->customer_id) {
                $validator->errors()->add('counterparty_id', 'The customer must match the sales invoice.');
            }
            if ($this->type === 'expense' && $document && (int) $this->offset_account_id !== (int) $document->chart_account_id) {
                $validator->errors()->add('offset_account_id', 'An expense payment must use the expense document account.');
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
