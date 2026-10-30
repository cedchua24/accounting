<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentPostingService
{
    public function __construct(
        private CreditCardCycleService $creditCardCycles,
        private CustomerRefundService $customerRefunds,
    ) {}

    public function post(Payment $payment, ?int $userId): Payment
    {
        return DB::transaction(function () use ($payment, $userId) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft payments can be posted.']);
            }

            $payment->load('lines.financialAccount.chartAccount', 'lines.paymentMethod', 'offsetAccount', 'allocations.obligation');
            $this->assertDocumentBalance($payment);
            $this->assertAllocationBalances($payment);
            $invalidLine = $payment->lines->contains(function ($line) use ($payment) {
                $account = $line->financialAccount;

                return ! $account->is_active
                    || $account->currency !== $payment->currency
                    || ! $account->isAvailableToBranch((int) $payment->branch_id)
                    || ($payment->direction === 'inflow' && ! $account->usable_for_receipts)
                    || ($payment->direction === 'outflow' && ! $account->usable_for_payments);
            });
            if (! $payment->offsetAccount->is_active || $invalidLine) {
                throw ValidationException::withMessages(['account' => 'A payment line contains an inactive, incompatible, or unauthorized financial account.']);
            }

            $lineTotal = $payment->lines->reduce(fn ($total, $line) => bcadd($total, (string) $line->amount, 4), '0.0000');
            if (bccomp($lineTotal, (string) $payment->amount, 4) !== 0) {
                throw ValidationException::withMessages(['lines' => 'Payment line totals no longer match the payment total.']);
            }

            $entry = $payment->journalEntries()->create([
                'kind' => 'payment',
                'number' => "JE-{$payment->number}",
                'entry_date' => $payment->payment_date,
                'memo' => $payment->memo,
                'created_by' => $userId,
            ]);

            $accountIsDebit = $payment->direction === 'inflow';
            $journalLines = $payment->lines->map(fn ($line) => [
                'branch_id' => $payment->branch_id,
                'chart_account_id' => $line->financialAccount->chart_account_id,
                'counterparty_id' => $payment->counterparty_id,
                'debit' => $accountIsDebit ? $line->amount : 0,
                'credit' => $accountIsDebit ? 0 : $line->amount,
                'description' => $line->paymentMethod->name.($line->reference ? ': '.$line->reference : ''),
            ])->all();
            $journalLines[] = [
                'branch_id' => $payment->branch_id,
                'chart_account_id' => $payment->offset_account_id,
                'counterparty_id' => $payment->counterparty_id,
                'debit' => $accountIsDebit ? 0 : $payment->amount,
                'credit' => $accountIsDebit ? $payment->amount : 0,
                'description' => 'Payment offset',
            ];
            $entry->lines()->createMany($journalLines);

            $payment->update(['status' => 'posted', 'posted_by' => $userId, 'posted_at' => now()]);
            $this->creditCardCycles->recordCharges($payment);
            $this->refreshDocumentStatus($payment);
            $this->refreshObligationStatuses($payment);

            return $payment->fresh($this->relations());
        });
    }

    public function void(Payment $payment, string $reason, ?int $userId): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $userId) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'Only posted payments can be voided.']);
            }

            if ($payment->refunds()->where('status', 'posted')->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'This payment has posted customer refunds. Void those refunds first.',
                ]);
            }

            if ($payment->lines()->whereHas('settlementItems.settlement', fn ($query) => $query->where('status', 'posted'))->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'This payment is included in a posted end-of-day settlement. Void the settlement first.',
                ]);
            }

            $this->creditCardCycles->assertCanVoid($payment);

            $original = $payment->journalEntries()->where('kind', 'payment')->with('lines')->firstOrFail();
            $reversal = $payment->journalEntries()->create([
                'kind' => 'reversal',
                'number' => "VOID-{$payment->number}",
                'entry_date' => now()->toDateString(),
                'memo' => $reason,
                'created_by' => $userId,
            ]);
            $reversal->lines()->createMany($original->lines->map(fn ($line) => [
                'chart_account_id' => $line->chart_account_id,
                'branch_id' => $line->branch_id,
                'counterparty_id' => $line->counterparty_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'description' => 'Reversal: '.$line->description,
            ])->all());

            $payment->update(['status' => 'voided', 'voided_by' => $userId, 'voided_at' => now(), 'void_reason' => $reason]);
            $this->creditCardCycles->refreshForPayment($payment);
            $this->refreshDocumentStatus($payment);
            $this->refreshObligationStatuses($payment);

            return $payment->fresh($this->relations());
        });
    }

    private function refreshDocumentStatus(Payment $payment): void
    {
        if ($payment->type === 'customer' && $payment->salesInvoice) {
            $this->customerRefunds->refreshInvoiceStatus($payment->salesInvoice);
            return;
        }

        $document = match ($payment->type) {
            'purchase_order' => $payment->purchaseOrder,
            'expense' => $payment->expense,
            default => null,
        };

        if (! $document) {
            return;
        }

        $paid = $document->payments()->where('status', 'posted')->sum('amount');
        $total = $payment->type === 'expense' ? $document->amount : $document->total_amount;
        $unpaidStatus = $payment->type === 'purchase_order' ? 'open' : 'unpaid';
        $document->update(['status' => $paid <= 0 ? $unpaidStatus : ($paid >= $total ? 'paid' : 'partially_paid')]);
    }

    private function assertDocumentBalance(Payment $payment): void
    {
        $document = match ($payment->type) {
            'customer' => $payment->salesInvoice()->lockForUpdate()->first(),
            'purchase_order' => $payment->purchaseOrder()->lockForUpdate()->first(),
            'expense' => $payment->expense()->lockForUpdate()->first(),
            default => null,
        };
        if (! $document) {
            return;
        }

        if ($payment->type === 'expense' && $document->approval_status !== 'approved') {
            throw ValidationException::withMessages([
                'expense_id' => 'The expense must be approved before payment can be posted.',
            ]);
        }

        $total = $payment->type === 'expense' ? $document->amount : $document->total_amount;
        $paid = $document->payments()->where('status', 'posted')->sum('amount');
        if (in_array($document->status, ['paid', 'refunded', 'cancelled']) || (float) $payment->amount > (float) $total - (float) $paid) {
            throw ValidationException::withMessages(['amount' => 'The payment exceeds the current payable balance.']);
        }
    }

    private function assertAllocationBalances(Payment $payment): void
    {
        foreach ($payment->allocations as $allocation) {
            $obligation = \App\Models\PaymentObligation::query()->lockForUpdate()->findOrFail($allocation->payment_obligation_id);
            $alreadyPaid = $obligation->allocations()
                ->where('payment_id', '!=', $payment->id)
                ->whereHas('payment', fn ($query) => $query->where('status', 'posted'))
                ->sum('amount');
            $total = (float) $obligation->principal_amount + (float) $obligation->interest_amount + (float) $obligation->fee_amount + (float) $obligation->adjustment_amount;
            if ($obligation->status === 'voided' || $obligation->currency !== $payment->currency || (float) $allocation->amount > $total - (float) $alreadyPaid) {
                throw ValidationException::withMessages(['allocations' => "Invalid or excessive allocation for obligation {$obligation->number}."]);
            }
            if ($obligation->branch_id && (int) $obligation->branch_id !== (int) $payment->branch_id) {
                throw ValidationException::withMessages(['allocations' => "Obligation {$obligation->number} belongs to another branch."]);
            }
        }
    }

    private function refreshObligationStatuses(Payment $payment): void
    {
        foreach ($payment->allocations()->with('obligation')->get() as $allocation) {
            $obligation = $allocation->obligation;
            $paid = $obligation->allocations()->whereHas('payment', fn ($query) => $query->where('status', 'posted'))->sum('amount');
            $total = (float) $obligation->principal_amount + (float) $obligation->interest_amount + (float) $obligation->fee_amount + (float) $obligation->adjustment_amount;
            $status = $paid >= $total ? 'paid' : ($paid > 0 ? 'partially_paid' : ($obligation->due_date->isPast() ? 'overdue' : 'open'));
            $obligation->update(['status' => $status]);
        }
    }

    private function relations(): array
    {
        return ['branch', 'lines.financialAccount.institution', 'lines.financialAccount.ownerBranch', 'lines.financialAccount.chartAccount', 'lines.paymentMethod', 'lines.obligationCharges.obligation', 'counterparty', 'salesInvoice', 'purchaseOrder', 'expense', 'offsetAccount', 'allocations.obligation', 'journalEntries.lines.chartAccount'];
    }
}
