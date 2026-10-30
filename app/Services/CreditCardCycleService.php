<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\ObligationCharge;
use App\Models\Payment;
use App\Models\PaymentObligation;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class CreditCardCycleService
{
    public function recordCharges(Payment $payment): void
    {
        if ($payment->direction !== 'outflow') {
            return;
        }

        foreach ($payment->lines as $line) {
            $account = $line->financialAccount;
            if ($account->kind !== 'credit_card') {
                continue;
            }

            [$firstStatementDate] = $this->cycleDates($payment->payment_date, $account);
            $installmentCount = max(1, (int) $line->installment_count);
            $regularAmount = bcdiv((string) $line->amount, (string) $installmentCount, 4);

            for ($number = 1; $number <= $installmentCount; $number++) {
                $statementDate = $this->dayInMonth(
                    $firstStatementDate->copy()->addMonthsNoOverflow($number - 1),
                    $account->statement_day
                );
                $dueDate = $this->dueDateForStatement($statementDate, $account->due_day);
                $amount = $number === $installmentCount
                    ? bcsub((string) $line->amount, bcmul($regularAmount, (string) ($installmentCount - 1), 4), 4)
                    : $regularAmount;
                $obligation = $this->statementObligation($account, $statementDate, $dueDate);

                ObligationCharge::firstOrCreate(
                    ['payment_line_id' => $line->id, 'installment_number' => $number],
                    ['payment_obligation_id' => $obligation->id, 'amount' => $amount]
                );
                $this->recalculate($obligation);
                $this->applyAvailableCredit($account, $obligation);
                $this->recalculate($obligation);
            }
        }
    }

    public function assertCanVoid(Payment $payment): void
    {
        foreach ($payment->lines()->with('obligationCharges.obligation')->get() as $line) {
            foreach ($line->obligationCharges as $charge) {
                $obligation = $charge->obligation;
                $remainingCharges = $obligation->charges()
                    ->where('payment_line_id', '!=', $line->id)
                    ->whereHas('paymentLine.payment', fn ($query) => $query->where('status', 'posted'))
                    ->sum('amount');
                $paid = $this->paidAmount($obligation);
                $newTotal = (float) $remainingCharges + (float) $obligation->interest_amount + (float) $obligation->fee_amount + (float) $obligation->adjustment_amount;
                if ((float) $paid > $newTotal) {
                    throw ValidationException::withMessages(['status' => 'This card charge cannot be voided because an installment statement has already been settled beyond the remaining balance.']);
                }
            }
        }
    }

    public function refreshForPayment(Payment $payment): void
    {
        $payment->lines()->with('obligationCharges.obligation')->get()
            ->flatMap->obligationCharges->pluck('obligation')->filter()->unique('id')
            ->each(fn ($obligation) => $this->recalculate($obligation));
    }

    public function cycleDates(Carbon $purchaseDate, FinancialAccount $account): array
    {
        if (! $account->statement_day || ! $account->due_day) {
            throw ValidationException::withMessages(['financial_account' => 'Credit-card accounts require statement_day and due_day.']);
        }

        $statementDate = $this->dayInMonth($purchaseDate->copy(), $account->statement_day);
        if ($purchaseDate->gt($statementDate)) {
            $statementDate = $this->dayInMonth($statementDate->copy()->addMonthNoOverflow(), $account->statement_day);
        }

        $dueDate = $this->dueDateForStatement($statementDate, $account->due_day);

        return [$statementDate, $dueDate];
    }

    private function statementObligation(FinancialAccount $account, Carbon $statementDate, Carbon $dueDate): PaymentObligation
    {
        return PaymentObligation::firstOrCreate(
            [
                'type' => 'credit_card_statement',
                'financial_account_id' => $account->id,
                'statement_date' => $statementDate->toDateString(),
            ],
            [
                'number' => 'CCS-'.$account->id.'-'.$statementDate->format('Ymd'),
                'branch_id' => $account->owner_branch_id,
                'offset_account_id' => $account->chart_account_id,
                'due_date' => $dueDate->toDateString(),
                'currency' => $account->currency,
                'principal_amount' => 0,
                'status' => 'open',
                'notes' => 'Automatically generated credit-card statement cycle.',
            ]
        );
    }

    private function dueDateForStatement(Carbon $statementDate, int $dueDay): Carbon
    {
        $dueDate = $this->dayInMonth($statementDate->copy(), $dueDay);
        if (! $dueDate->gt($statementDate)) {
            $dueDate = $this->dayInMonth($statementDate->copy()->addMonthNoOverflow(), $dueDay);
        }

        return $dueDate;
    }

    private function dayInMonth(Carbon $date, int $day): Carbon
    {
        return $date->startOfMonth()->day(min($day, $date->daysInMonth));
    }

    private function recalculate(PaymentObligation $obligation): void
    {
        $principal = $obligation->charges()
            ->whereHas('paymentLine.payment', fn ($query) => $query->where('status', 'posted'))
            ->sum('amount');
        $paid = $this->paidAmount($obligation);
        $total = (float) $principal + (float) $obligation->interest_amount + (float) $obligation->fee_amount + (float) $obligation->adjustment_amount;
        $status = $total <= 0 ? 'voided' : ($paid >= $total ? 'paid' : ($paid > 0 ? 'partially_paid' : ($obligation->due_date->isPast() ? 'overdue' : 'open')));
        $obligation->update(['principal_amount' => $principal, 'status' => $status]);
    }

    private function paidAmount(PaymentObligation $obligation)
    {
        return $obligation->allocations()->whereHas('payment', fn ($query) => $query->where('status', 'posted'))->sum('amount');
    }

    private function applyAvailableCredit(FinancialAccount $account, PaymentObligation $obligation): void
    {
        $balanceDue = (string) $obligation->balance_due;
        if (bccomp($balanceDue, '0.0000', 4) !== 1) {
            return;
        }

        $payments = Payment::query()
            ->where('type', 'chart_account')
            ->where('direction', 'outflow')
            ->where('status', 'posted')
            ->where('offset_account_id', $account->chart_account_id)
            ->where('currency', $account->currency)
            ->withSum('allocations as allocated_amount', 'amount')
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        foreach ($payments as $payment) {
            if (bccomp($balanceDue, '0.0000', 4) !== 1) {
                break;
            }

            $availableCredit = bcsub((string) $payment->amount, (string) ($payment->allocated_amount ?? 0), 4);
            if (bccomp($availableCredit, '0.0000', 4) !== 1) {
                continue;
            }

            $applied = bccomp($availableCredit, $balanceDue, 4) === 1 ? $balanceDue : $availableCredit;
            $allocation = $payment->allocations()->firstOrNew([
                'payment_obligation_id' => $obligation->id,
            ]);
            $allocation->amount = bcadd((string) ($allocation->amount ?? 0), $applied, 4);
            $allocation->save();
            $balanceDue = bcsub($balanceDue, $applied, 4);
        }
    }
}
