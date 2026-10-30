<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\CustomerRefund;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerRefundService
{
    public function create(array $data, ?int $userId): CustomerRefund
    {
        return DB::transaction(function () use ($data, $userId) {
            if (! empty($data['idempotency_key'])) {
                $existing = CustomerRefund::where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing->load($this->relations());
                }
            }

            $payment = Payment::query()->lockForUpdate()->findOrFail($data['original_payment_id']);
            $this->assertOriginalPayment($payment);
            $refundAccount = ChartAccount::firstOrCreate(
                ['code' => 'SALES-RETURNS'],
                ['name' => 'Sales Returns and Allowances', 'type' => 'revenue', 'is_active' => true]
            );
            $post = ! empty($data['post']);
            unset($data['post']);
            $refund = CustomerRefund::create([
                ...$data,
                'number' => 'REF-'.Str::upper((string) Str::ulid()),
                'sales_invoice_id' => $payment->sales_invoice_id,
                'branch_id' => $payment->branch_id,
                'customer_id' => $payment->counterparty_id,
                'refund_account_id' => $refundAccount->id,
                'created_by' => $userId,
            ]);

            return $post
                ? $this->post($refund, $userId)
                : $refund->load($this->relations());
        });
    }

    public function post(CustomerRefund $refund, ?int $userId): CustomerRefund
    {
        return DB::transaction(function () use ($refund, $userId) {
            $refund = CustomerRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft refunds can be posted.']);
            }

            $payment = Payment::query()->lockForUpdate()->findOrFail($refund->original_payment_id);
            $this->assertOriginalPayment($payment);
            $alreadyRefunded = CustomerRefund::where('original_payment_id', $payment->id)
                ->where('status', 'posted')->sum('amount');
            $refundable = bcsub((string) $payment->amount, (string) $alreadyRefunded, 4);
            if (bccomp((string) $refund->amount, $refundable, 4) === 1) {
                throw ValidationException::withMessages(['amount' => 'The refund exceeds the remaining refundable payment amount.']);
            }

            $account = FinancialAccount::with('chartAccount', 'applications')->lockForUpdate()->findOrFail($refund->financial_account_id);
            $method = PaymentMethod::findOrFail($refund->payment_method_id);
            $this->assertRefundAccount($account, $method, $refund);

            $available = JournalLine::where('chart_account_id', $account->chart_account_id)
                ->selectRaw('COALESCE(SUM(debit - credit), 0) AS balance')->value('balance');
            if (bccomp((string) $available, (string) $refund->amount, 4) === -1) {
                throw ValidationException::withMessages(['amount' => 'The refund exceeds the selected account balance.']);
            }

            $entry = $refund->journalEntries()->create([
                'kind' => 'customer_refund',
                'number' => 'JE-'.$refund->number,
                'entry_date' => $refund->refund_date,
                'memo' => $refund->reason,
                'created_by' => $userId,
            ]);
            $entry->lines()->createMany([
                [
                    'branch_id' => $refund->branch_id,
                    'chart_account_id' => $refund->refund_account_id,
                    'counterparty_id' => $refund->customer_id,
                    'debit' => $refund->amount, 'credit' => 0,
                    'description' => 'Customer return: '.$refund->reason,
                ],
                [
                    'branch_id' => $refund->branch_id,
                    'chart_account_id' => $account->chart_account_id,
                    'counterparty_id' => $refund->customer_id,
                    'debit' => 0, 'credit' => $refund->amount,
                    'description' => $method->name.($refund->reference ? ': '.$refund->reference : ''),
                ],
            ]);
            $refund->update(['status' => 'posted', 'posted_by' => $userId, 'posted_at' => now()]);
            $this->refreshInvoiceStatus($refund->salesInvoice);

            return $refund->fresh($this->relations());
        });
    }

    public function void(CustomerRefund $refund, string $reason, ?int $userId): CustomerRefund
    {
        return DB::transaction(function () use ($refund, $reason, $userId) {
            $refund = CustomerRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'Only posted refunds can be voided.']);
            }

            $original = $refund->journalEntries()->where('kind', 'customer_refund')->with('lines')->firstOrFail();
            $reversal = $refund->journalEntries()->create([
                'kind' => 'customer_refund_reversal',
                'number' => 'VOID-'.$refund->number,
                'entry_date' => now()->toDateString(),
                'memo' => $reason,
                'created_by' => $userId,
            ]);
            $reversal->lines()->createMany($original->lines->map(fn ($line) => [
                'branch_id' => $line->branch_id,
                'chart_account_id' => $line->chart_account_id,
                'counterparty_id' => $line->counterparty_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'description' => 'Reversal: '.$line->description,
            ])->all());
            $refund->update([
                'status' => 'voided', 'voided_by' => $userId,
                'voided_at' => now(), 'void_reason' => $reason,
            ]);
            $this->refreshInvoiceStatus($refund->salesInvoice);

            return $refund->fresh($this->relations());
        });
    }

    public function refreshInvoiceStatus(SalesInvoice $invoice): void
    {
        $received = (string) $invoice->payments()->where('status', 'posted')->sum('amount');
        $refunded = (string) $invoice->refunds()->where('status', 'posted')->sum('amount');
        $netSale = max(0, (float) $invoice->total_amount - (float) $refunded);
        $netReceived = max(0, (float) $received - (float) $refunded);

        $status = (float) $refunded >= (float) $invoice->total_amount
            ? 'refunded'
            : ((float) $refunded > 0
                ? 'partially_refunded'
                : ($netReceived <= 0 ? 'unpaid' : ($netReceived >= $netSale ? 'paid' : 'partially_paid')));
        $invoice->update(['status' => $status]);
    }

    private function assertOriginalPayment(Payment $payment): void
    {
        if ($payment->type !== 'customer' || $payment->status !== 'posted' || ! $payment->sales_invoice_id) {
            throw ValidationException::withMessages(['original_payment_id' => 'Refunds require a posted customer payment.']);
        }
    }

    private function assertRefundAccount(FinancialAccount $account, PaymentMethod $method, CustomerRefund $refund): void
    {
        $applications = $account->applications->pluck('application');
        if (! $account->is_active || $account->currency !== $refund->currency
            || $account->kind === 'credit_card' || $account->chartAccount?->type !== 'asset'
            || ! $account->usable_for_payments
            || ! $account->isAvailableToBranch((int) $refund->branch_id)
            || ($applications->isNotEmpty() && ! $applications->contains(FinancialAccountApplication::CUSTOMER_RECEIPT))) {
            throw ValidationException::withMessages(['financial_account_id' => 'Select an available customer-receipt cash, bank, or e-wallet account.']);
        }
        $methodMatchesAccount = in_array($account->kind, $method->compatibleFundingAccountKinds(), true);
        if (! $method->is_active || $method->creates_obligation
            || ! in_array($method->type, ['cash', 'bank_transfer', 'e_wallet'], true)
            || ! $methodMatchesAccount) {
            throw ValidationException::withMessages(['payment_method_id' => 'Select an active refund method compatible with the selected account.']);
        }
    }

    private function relations(): array
    {
        return [
            'originalPayment', 'salesInvoice', 'branch', 'customer',
            'financialAccount.institution', 'financialAccount.chartAccount',
            'paymentMethod', 'refundAccount', 'journalEntries.lines.chartAccount',
        ];
    }
}
