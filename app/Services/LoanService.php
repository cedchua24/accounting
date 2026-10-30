<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanInstallmentAdjustment;
use App\Models\LoanPayment;
use App\Models\LoanPaymentAllocation;
use App\Models\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public function create(array $data): Loan
    {
        return DB::transaction(function () use ($data) {
            $data['number'] = $data['number'] ?? 'LOAN-'.Str::upper((string) Str::ulid());
            $data['borrower_type'] = $data['borrower_type'] ?? 'company';
            $data = $this->normalizeLender($data);
            $loan = Loan::create($data);
            $this->createSchedule($loan);

            return $this->present($loan->fresh(), now()->toDateString());
        });
    }

    public function update(Loan $loan, array $data): Loan
    {
        return DB::transaction(function () use ($loan, $data) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($loan->payments()->exists()) {
                throw ValidationException::withMessages(['loan' => 'A loan with payments cannot be edited.']);
            }
            if ($loan->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => 'A cancelled loan cannot be edited.']);
            }
            $data['borrower_type'] = $data['borrower_type'] ?? $loan->borrower_type;
            $loan->update($this->normalizeLender($data));
            $loan->installments()->delete();
            $this->createSchedule($loan);

            return $this->present($loan->fresh(), now()->toDateString());
        });
    }

    public function recordPayment(Loan $loan, array $data, ?int $userId = null): LoanPayment
    {
        return DB::transaction(function () use ($loan, $data, $userId) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($loan->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'Payments can only be recorded against an active loan.']);
            }
            $account = FinancialAccount::query()
                ->with('chartAccount')
                ->lockForUpdate()
                ->findOrFail($data['financial_account_id']);
            $method = PaymentMethod::query()->forLoanPayments()->find($data['payment_method_id']);
            if (! $method) {
                throw ValidationException::withMessages([
                    'payment_method_id' => 'Select Cash, Bank Transfer, Cheque, or E-Wallet.',
                ]);
            }
            if (! $account->is_active || $account->currency !== $loan->currency
                || ! $account->usable_for_payments
                || $account->chartAccount?->type !== 'asset'
                || ! in_array($account->kind, $method->compatibleFundingAccountKinds(), true)) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'Select an active payment account compatible with the payment method and loan currency.',
                ]);
            }

            $paymentDate = Carbon::parse($data['payment_date'])->startOfDay();
            if ($paymentDate->lt($loan->start_date)) {
                throw ValidationException::withMessages(['payment_date' => 'The payment date cannot be before the loan start date.']);
            }
            $latestPaymentDate = $loan->payments()->max('payment_date');
            if ($latestPaymentDate && $paymentDate->lt(Carbon::parse($latestPaymentDate))) {
                throw ValidationException::withMessages([
                    'payment_date' => 'Backdated payments cannot precede an existing loan payment.',
                ]);
            }
            $remaining = $this->units($data['amount']);
            $classifyManualInterest = ($data['excess_treatment'] ?? null) === 'overdue_interest';
            $data['excess_treatment'] = $data['excess_treatment'] ?? 'overdue_interest';
            $installments = $loan->installments()
                ->with(['allocations.payment'])
                ->orderBy('installment_number')->lockForUpdate()->get();
            $allocations = [];
            $targetInstallmentId = null;
            $overpayment = 0;

            foreach ($installments as $installment) {
                if ($remaining <= 0) {
                    break;
                }
                $lateDue = $this->units($this->lateInterestDue($installment, $paymentDate));
                $interestDue = max(0, $this->units($installment->interest_due) - $this->units($installment->interest_paid));
                $principalDue = max(0, $this->units($installment->principal_due) - $this->units($installment->principal_paid));
                if ($targetInstallmentId === null && $lateDue + $interestDue + $principalDue > 0) {
                    $targetInstallmentId = $installment->id;
                }
                $isTargetInstallment = $targetInstallmentId === $installment->id;
                $waivedLate = $data['excess_treatment'] === 'overpayment' && $isTargetInstallment
                    ? $lateDue
                    : 0;
                $late = $waivedLate > 0 ? 0 : min($remaining, $lateDue);
                $remaining -= $late;
                $interest = min($remaining, $interestDue);
                $remaining -= $interest;
                $principal = min($remaining, $principalDue);
                $remaining -= $principal;
                if ($classifyManualInterest && $isTargetInstallment && $remaining > 0) {
                    // The user explicitly classified the payment excess as an interest expense.
                    // Keep it on the selected installment instead of advancing a future installment.
                    $late += $remaining;
                    $remaining = 0;
                }
                if ($data['excess_treatment'] === 'overpayment' && ! $isTargetInstallment) {
                    $overpayment += $interest + $principal;
                }

                if ($late + $interest + $principal + $waivedLate > 0) {
                    $allocations[] = [
                        'loan_installment_id' => $installment->id,
                        'overdue_interest_amount' => $this->money($late),
                        'overdue_interest_waived_amount' => $this->money($waivedLate),
                        'interest_amount' => $this->money($interest),
                        'principal_amount' => $this->money($principal),
                    ];
                }
            }

            if ($remaining > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'The payment exceeds the loan balance, including overdue interest as of the payment date.',
                ]);
            }

            $data['overpayment_amount'] = $this->money($overpayment);
            $payment = $loan->payments()->create($data);
            $payment->allocations()->createMany($allocations);
            $this->postPaymentJournal($payment, $loan, $account, $userId);
            $this->refreshLoanStatus($loan);

            return $payment->fresh([
                'loan', 'paymentMethod', 'financialAccount.institution', 'allocations.installment',
                'journalEntries.lines.chartAccount',
            ]);
        });
    }

    public function deletePayment(LoanPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($payment->loan_id);
            $latestPayment = $loan->payments()->latest('payment_date')->latest('id')->first();
            if (! $latestPayment || $latestPayment->isNot($payment)) {
                throw ValidationException::withMessages([
                    'payment' => 'Only the most recent loan payment can be deleted.',
                ]);
            }
            $payment->journalEntries()->with('lines')->get()->each(function ($entry) {
                $entry->lines->each->delete();
                $entry->delete();
            });
            $payment->delete();
            if ($loan->status !== 'cancelled') {
                $loan->update(['status' => 'active']);
                $this->refreshLoanStatus($loan);
            }
        });
    }

    public function adjustInstallment(
        Loan $loan,
        LoanInstallment $installment,
        array $data,
        ?int $userId = null,
    ): LoanInstallmentAdjustment {
        return DB::transaction(function () use ($loan, $installment, $data, $userId) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);
            $installment = LoanInstallment::query()->lockForUpdate()->findOrFail($installment->id);
            if ((int) $installment->loan_id !== (int) $loan->id) {
                abort(404);
            }
            if ($loan->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'loan' => 'A cancelled loan installment cannot be adjusted.',
                ]);
            }

            $allocations = $installment->allocations()->lockForUpdate()->get();
            $principalPaid = $allocations->sum(fn ($allocation) => $this->units($allocation->principal_amount));
            $interestPaid = $allocations->sum(fn ($allocation) => $this->units($allocation->interest_amount));
            $oldPrincipal = $this->units($installment->principal_due);
            $oldInterest = $this->units($installment->interest_due);
            if ($principalPaid >= $oldPrincipal && $interestPaid >= $oldInterest) {
                throw ValidationException::withMessages([
                    'installment' => 'A fully paid installment cannot be adjusted.',
                ]);
            }

            $newDueDate = Carbon::parse($data['due_date'] ?? $installment->due_date)->startOfDay();
            $newPrincipal = array_key_exists('principal_due', $data)
                ? $this->units($data['principal_due'])
                : $oldPrincipal;
            $newInterest = array_key_exists('interest_due', $data)
                ? $this->units($data['interest_due'])
                : $oldInterest;

            if ($newDueDate->lt($loan->start_date)) {
                throw ValidationException::withMessages([
                    'due_date' => 'The installment due date cannot be before the loan start date.',
                ]);
            }
            $previous = $loan->installments()
                ->where('installment_number', '<', $installment->installment_number)
                ->latest('installment_number')
                ->lockForUpdate()
                ->first();
            $next = $loan->installments()
                ->where('installment_number', '>', $installment->installment_number)
                ->oldest('installment_number')
                ->lockForUpdate()
                ->first();
            if ($previous && $newDueDate->lte($previous->due_date)) {
                throw ValidationException::withMessages([
                    'due_date' => 'The due date must be after the previous installment.',
                ]);
            }
            if ($next && $newDueDate->gte($next->due_date)) {
                throw ValidationException::withMessages([
                    'due_date' => 'The due date must be before the next installment.',
                ]);
            }
            if ($newPrincipal < $principalPaid) {
                throw ValidationException::withMessages([
                    'principal_due' => 'Principal cannot be less than the principal already paid.',
                ]);
            }
            if ($newInterest < $interestPaid) {
                throw ValidationException::withMessages([
                    'interest_due' => 'Interest cannot be less than the scheduled interest already paid.',
                ]);
            }
            if (
                $newDueDate->isSameDay($installment->due_date)
                && $newPrincipal === $oldPrincipal
                && $newInterest === $oldInterest
            ) {
                throw ValidationException::withMessages([
                    'installment' => 'The submitted values do not change this installment.',
                ]);
            }

            $adjustment = $installment->adjustments()->create([
                'loan_id' => $loan->id,
                'adjusted_by' => $userId,
                'old_due_date' => $installment->due_date,
                'new_due_date' => $newDueDate,
                'old_principal_due' => $this->money($oldPrincipal),
                'new_principal_due' => $this->money($newPrincipal),
                'old_interest_due' => $this->money($oldInterest),
                'new_interest_due' => $this->money($newInterest),
                'reason' => trim($data['reason']),
            ]);
            $installment->update([
                'due_date' => $newDueDate,
                'principal_due' => $this->money($newPrincipal),
                'interest_due' => $this->money($newInterest),
            ]);

            $scheduleTotals = $loan->installments()
                ->selectRaw('COALESCE(SUM(principal_due), 0) AS principal_amount')
                ->selectRaw('COALESCE(SUM(interest_due), 0) AS interest_amount')
                ->first();
            $firstDueDate = $loan->installments()->oldest('installment_number')->value('due_date');
            $loan->update([
                'first_due_date' => $firstDueDate,
                'principal_amount' => $scheduleTotals->principal_amount,
                'total_interest_amount' => $scheduleTotals->interest_amount,
            ]);
            $this->refreshLoanStatus($loan);

            return $adjustment->fresh();
        });
    }

    public function present(Loan $loan, string $asOf): Loan
    {
        $date = Carbon::parse($asOf)->startOfDay();
        $loan->load([
            'counterparty', 'financialInstitution', 'userAccount', 'financialAccount.institution',
            'installments.allocations.payment.paymentMethod',
            'installments.allocations.payment.financialAccount.institution',
            'payments.paymentMethod',
            'payments.financialAccount.institution', 'payments.allocations',
        ]);

        $payments = $loan->payments
            ->filter(fn ($payment) => $payment->payment_date->lte($date))
            ->values();
        $installmentOrder = $loan->installments->pluck('installment_number', 'id');
        $paymentTargets = $payments
            ->mapWithKeys(function ($payment) use ($installmentOrder) {
                $target = $payment->allocations
                    ->sortBy(fn ($allocation) => $installmentOrder->get($allocation->loan_installment_id, PHP_INT_MAX))
                    ->first();

                return [$payment->id => $target?->loan_installment_id];
            });

        $scheduledOutstanding = 0;
        $lateOutstanding = 0;
        $interestPaidTotal = 0;
        $overdueInterestPaidTotal = 0;
        foreach ($loan->installments as $installment) {
            $installment->setRelation('allocations', $installment->allocations
                ->filter(fn ($allocation) => $allocation->payment && $allocation->payment->payment_date->lte($date))
                ->values());
            $scheduled = $this->units($installment->scheduled_amount);
            $scheduledPaid = $this->units($installment->principal_paid) + $this->units($installment->interest_paid);
            $targetPayments = $installment->allocations
                ->filter(fn ($allocation) => $paymentTargets->get($allocation->loan_payment_id) === $installment->id)
                ->pluck('payment')
                ->unique('id');
            $paymentReceived = $targetPayments->sum(fn ($payment) => $this->units($payment->amount));
            $overpayment = $targetPayments->sum(fn ($payment) => $this->units($payment->overpayment_amount));
            $overpaymentApplied = $installment->allocations
                ->filter(fn ($allocation) => $paymentTargets->get($allocation->loan_payment_id) !== $installment->id)
                ->sum(fn ($allocation) => $this->units($allocation->principal_amount)
                    + $this->units($allocation->interest_amount));
            $interestPaid = $this->units($installment->interest_paid);
            $overdueInterestPaid = $this->units($installment->overdue_interest_paid);
            $displayPaid = $paymentReceived + $overpaymentApplied;
            $outstanding = max(0, $scheduled - $scheduledPaid);
            $late = $this->units($this->lateInterestDue($installment, $date));
            $scheduledOutstanding += $outstanding;
            $lateOutstanding += $late;
            $interestPaidTotal += $interestPaid;
            $overdueInterestPaidTotal += $overdueInterestPaid;
            $installment->setAttribute('paid_amount', $this->money($displayPaid));
            $installment->setAttribute('payment_received_amount', $this->money($paymentReceived));
            $installment->setAttribute('scheduled_paid_amount', $this->money($scheduledPaid));
            $installment->setAttribute('overpayment_amount', $this->money($overpayment));
            $installment->setAttribute('overpayment_applied_amount', $this->money($overpaymentApplied));
            $installment->setAttribute('interest_paid_amount', $this->money($interestPaid));
            $installment->setAttribute('overdue_interest_paid_amount', $this->money($overdueInterestPaid));
            $installment->setAttribute(
                'total_interest_paid_amount',
                $this->money($interestPaid + $overdueInterestPaid)
            );
            $installment->setAttribute('payment_entries', $installment->allocations
                ->sortBy(fn ($allocation) => $allocation->payment->payment_date->format('Y-m-d').' '.str_pad(
                    (string) $allocation->payment->id,
                    20,
                    '0',
                    STR_PAD_LEFT
                ))
                ->map(fn ($allocation) => $this->paymentEntry(
                    $allocation->payment,
                    $allocation,
                    $paymentTargets->get($allocation->loan_payment_id) === $installment->id
                ))
                ->values()
                ->all());
            $installment->setAttribute('outstanding_amount', $this->money($outstanding));
            $installment->setAttribute('overdue_interest_due', $this->money($late));
            $installment->setAttribute('total_due', $this->money($outstanding + $late));
            $installment->setAttribute('status', $outstanding === 0 && $late === 0
                ? 'paid'
                : ($scheduledPaid > 0 ? 'partial' : ($date->gt($installment->due_date) ? 'overdue' : 'pending')));
        }
        $loan->setRelation('payments', $payments);
        if ($loan->status !== 'cancelled') {
            $loan->setAttribute('status', $scheduledOutstanding + $lateOutstanding === 0 ? 'paid' : 'active');
        }
        $loan->setAttribute('overpayment_amount', $this->money($payments->sum(
            fn ($payment) => $this->units($payment->overpayment_amount)
        )));
        $loan->setAttribute('interest_paid_amount', $this->money($interestPaidTotal));
        $loan->setAttribute('overdue_interest_paid_amount', $this->money($overdueInterestPaidTotal));
        $loan->setAttribute(
            'total_interest_paid_amount',
            $this->money($interestPaidTotal + $overdueInterestPaidTotal)
        );
        $loan->setAttribute('payment_entries', $payments
            ->map(fn ($payment) => $this->paymentEntry($payment))
            ->all());
        $loan->setAttribute('as_of_date', $date->toDateString());
        $loan->setAttribute('scheduled_balance', $this->money($scheduledOutstanding));
        $loan->setAttribute('overdue_interest_balance', $this->money($lateOutstanding));
        $loan->setAttribute('balance_due', $this->money($scheduledOutstanding + $lateOutstanding));

        return $loan;
    }

    public function summary(Loan $loan, string $asOf): Loan
    {
        $loan = $this->present($loan, $asOf);
        $nextInstallment = $loan->installments->first(fn ($installment) => $installment->status !== 'paid');
        $paid = max(0, $this->units($loan->total_amount) - $this->units($loan->scheduled_balance));

        $loan->setAttribute('party', $loan->borrower_name);
        $loan->setAttribute('user_account_name', $loan->userAccount?->name);
        $loan->setAttribute('funding_type', $loan->financialAccount?->kind ?? $loan->borrower_type);
        $loan->setAttribute('installment_amount', $loan->installments->isEmpty()
            ? '0.0000'
            : $loan->installments->first()->scheduled_amount);
        $loan->setAttribute('interest_amount', (string) $loan->total_interest_amount);
        $loan->setAttribute('total_loan_amount', (string) $loan->total_amount);
        $loan->setAttribute('balance_amount', (string) $loan->balance_due);
        $loan->setAttribute(
            'interest_expense_amount',
            $loan->direction === 'borrowed' ? (string) $loan->total_interest_paid_amount : '0.0000'
        );
        $loan->setAttribute(
            'overdue_interest_expense_amount',
            $loan->direction === 'borrowed' ? (string) $loan->overdue_interest_paid_amount : '0.0000'
        );
        $loan->setAttribute('amount_paid', $this->money($paid));
        $loan->setAttribute('payment_count', $loan->payments->count());
        $loan->setAttribute('overdue_installment_count', $loan->installments->where('status', 'overdue')->count());
        $loan->setAttribute('next_due_date', $nextInstallment?->due_date?->toDateString());
        $loan->setAttribute('next_due_amount', $nextInstallment?->total_due ?? '0.0000');
        $loan->makeHidden(['installments', 'payments']);

        return $loan;
    }

    public function lateInterestDue(LoanInstallment $installment, Carbon $asOf): string
    {
        $loan = $installment->relationLoaded('loan') ? $installment->loan : $installment->loan()->first();
        if ((float) $loan->overdue_interest_rate <= 0 || $asOf->lte($installment->due_date)) {
            return '0.0000';
        }

        $balance = $this->units($installment->scheduled_amount);
        $accrued = 0.0;
        $cursor = $installment->due_date->copy()->startOfDay();
        $latePaid = 0;
        $lateWaived = 0;
        $allocations = $installment->allocations
            ->filter(fn ($allocation) => $allocation->payment && $allocation->payment->payment_date->lte($asOf))
            ->sortBy(fn ($allocation) => $allocation->payment->payment_date->format('Y-m-d').' '.str_pad((string) $allocation->id, 20, '0', STR_PAD_LEFT));

        foreach ($allocations as $allocation) {
            $paymentDate = $allocation->payment->payment_date->copy()->startOfDay();
            if ($paymentDate->gt($cursor)) {
                $accrued += $this->lateCharge($balance, $cursor->diffInDays($paymentDate), $loan);
                $cursor = $paymentDate;
            }
            $balance = max(0, $balance - $this->units($allocation->principal_amount) - $this->units($allocation->interest_amount));
            $latePaid += $this->units($allocation->overdue_interest_amount);
            $lateWaived += $this->units($allocation->overdue_interest_waived_amount);
        }
        if ($asOf->gt($cursor)) {
            $accrued += $this->lateCharge($balance, $cursor->diffInDays($asOf), $loan);
        }

        return $this->money(max(0, (int) round($accrued) - $latePaid - $lateWaived));
    }

    private function createSchedule(Loan $loan): void
    {
        $principal = $this->units($loan->principal_amount);
        $interest = $this->units($loan->total_interest_amount);
        $total = $principal + $interest;
        $principalBase = intdiv($principal, $loan->term_months);
        $totalBase = intdiv($total, $loan->term_months);
        $principalRemainder = $principal % $loan->term_months;
        $totalRemainder = $total % $loan->term_months;
        $firstDue = $loan->first_due_date->copy();
        $rows = [];
        for ($number = 1; $number <= $loan->term_months; $number++) {
            $principalDue = $principalBase + ($number <= $principalRemainder ? 1 : 0);
            $totalDue = $totalBase + ($number <= $totalRemainder ? 1 : 0);
            $rows[] = [
                'installment_number' => $number,
                'due_date' => $firstDue->copy()->addMonthsNoOverflow($number - 1)->toDateString(),
                'principal_due' => $this->money($principalDue),
                'interest_due' => $this->money($totalDue - $principalDue),
            ];
        }
        $loan->installments()->createMany($rows);
    }

    private function normalizeLender(array $data): array
    {
        if (($data['borrower_type'] ?? 'company') === 'bank') {
            return array_merge($data, [
                'financial_account_id' => null,
                'counterparty_id' => null,
                'party_name' => null,
            ]);
        }

        return array_merge($data, [
            'financial_institution_id' => null,
            'user_account_id' => null,
        ]);
    }

    private function refreshLoanStatus(Loan $loan): void
    {
        $scheduled = $this->units($loan->total_amount);
        $paid = $this->units(DB::table('loan_payment_allocations')
            ->join('loan_installments', 'loan_installments.id', '=', 'loan_payment_allocations.loan_installment_id')
            ->where('loan_installments.loan_id', $loan->id)
            ->selectRaw('COALESCE(SUM(loan_payment_allocations.principal_amount + loan_payment_allocations.interest_amount), 0) AS paid')
            ->value('paid'));
        $loan->update(['status' => $paid >= $scheduled ? 'paid' : 'active']);
    }

    private function postPaymentJournal(
        LoanPayment $payment,
        Loan $loan,
        FinancialAccount $account,
        ?int $userId,
    ): void {
        $payment->loadMissing('allocations');
        $principal = $payment->allocations->reduce(
            fn (string $total, $allocation) => bcadd($total, (string) $allocation->principal_amount, 4),
            '0.0000'
        );
        $interest = $payment->allocations->reduce(
            fn (string $total, $allocation) => bcadd($total, (string) $allocation->interest_amount, 4),
            '0.0000'
        );
        $overdueInterest = $payment->allocations->reduce(
            fn (string $total, $allocation) => bcadd($total, (string) $allocation->overdue_interest_amount, 4),
            '0.0000'
        );
        $amount = bcadd((string) $payment->amount, '0', 4);
        $difference = bcsub($amount, bcadd($principal, bcadd($interest, $overdueInterest, 4), 4), 4);
        if (bccomp($difference, '0.0000', 4) !== 0) {
            $principal = bcadd($principal, $difference, 4);
        }

        $principalAccount = ChartAccount::firstOrCreate(
            ['code' => $loan->direction === 'borrowed' ? 'LOAN-PAYABLE' : 'LOAN-RECEIVABLE'],
            [
                'name' => $loan->direction === 'borrowed' ? 'Loans Payable' : 'Loans Receivable',
                'type' => $loan->direction === 'borrowed' ? 'liability' : 'asset',
                'is_active' => true,
            ]
        );
        $interestAccount = ChartAccount::firstOrCreate(
            ['code' => $loan->direction === 'borrowed' ? 'LOAN-INTEREST-EXPENSE' : 'LOAN-INTEREST-INCOME'],
            [
                'name' => $loan->direction === 'borrowed' ? 'Loan Interest Expense' : 'Loan Interest Income',
                'type' => $loan->direction === 'borrowed' ? 'expense' : 'revenue',
                'is_active' => true,
            ]
        );
        $overdueInterestAccount = ChartAccount::firstOrCreate(
            ['code' => $loan->direction === 'borrowed' ? 'LOAN-OVERDUE-INTEREST' : 'LOAN-OVERDUE-INTEREST-INCOME'],
            [
                'name' => $loan->direction === 'borrowed' ? 'Loan Overdue Interest Expense' : 'Loan Overdue Interest Income',
                'type' => $loan->direction === 'borrowed' ? 'expense' : 'revenue',
                'is_active' => true,
            ]
        );

        $isInflow = $loan->direction === 'lent';
        $entry = $payment->journalEntries()->create([
            'kind' => 'payment',
            'number' => "JE-LOANPAY-{$payment->id}",
            'entry_date' => $payment->payment_date,
            'memo' => "Loan payment {$loan->number}",
            'created_by' => $userId,
        ]);
        $lines = [[
            'branch_id' => $account->owner_branch_id,
            'chart_account_id' => $account->chart_account_id,
            'counterparty_id' => $loan->counterparty_id,
            'debit' => $isInflow ? $amount : '0.0000',
            'credit' => $isInflow ? '0.0000' : $amount,
            'description' => $payment->reference
                ? "Loan payment: {$payment->reference}"
                : "Loan payment {$loan->number}",
        ]];
        if (bccomp($principal, '0.0000', 4) === 1) {
            $lines[] = [
                'branch_id' => $account->owner_branch_id,
                'chart_account_id' => $principalAccount->id,
                'counterparty_id' => $loan->counterparty_id,
                'debit' => $isInflow ? '0.0000' : $principal,
                'credit' => $isInflow ? $principal : '0.0000',
                'description' => 'Loan principal',
            ];
        }
        if (bccomp($interest, '0.0000', 4) === 1) {
            $lines[] = [
                'branch_id' => $account->owner_branch_id,
                'chart_account_id' => $interestAccount->id,
                'counterparty_id' => $loan->counterparty_id,
                'debit' => $isInflow ? '0.0000' : $interest,
                'credit' => $isInflow ? $interest : '0.0000',
                'description' => $loan->direction === 'borrowed'
                    ? 'Loan interest expense'
                    : 'Loan interest income',
            ];
        }
        if (bccomp($overdueInterest, '0.0000', 4) === 1) {
            $lines[] = [
                'branch_id' => $account->owner_branch_id,
                'chart_account_id' => $overdueInterestAccount->id,
                'counterparty_id' => $loan->counterparty_id,
                'debit' => $isInflow ? '0.0000' : $overdueInterest,
                'credit' => $isInflow ? $overdueInterest : '0.0000',
                'description' => $loan->direction === 'borrowed'
                    ? 'Loan overdue interest expense'
                    : 'Loan overdue interest income',
            ];
        }
        $entry->lines()->createMany($lines);
    }

    private function lateCharge(int $balanceUnits, int $days, Loan $loan): float
    {
        $divisor = match ($loan->overdue_interest_period) {
            'daily' => 1,
            'monthly' => 30,
            default => 365,
        };

        return $balanceUnits * ((float) $loan->overdue_interest_rate / 100) * ($days / $divisor);
    }

    private function paymentEntry(
        LoanPayment $payment,
        ?LoanPaymentAllocation $allocation = null,
        bool $isDirectPayment = true,
    ): array {
        $method = $payment->paymentMethod;
        $account = $payment->financialAccount;
        $institution = $account?->institution;
        $allocatedAmount = $allocation
            ? $this->units($allocation->principal_amount)
                + $this->units($allocation->interest_amount)
                + $this->units($allocation->overdue_interest_amount)
            : null;

        return [
            'payment_id' => $payment->id,
            'payment_date' => $payment->payment_date->toDateString(),
            'amount' => (string) $payment->amount,
            'allocation_id' => $allocation?->id,
            'allocated_amount' => $allocatedAmount === null ? null : $this->money($allocatedAmount),
            'principal_amount' => $allocation?->principal_amount,
            'interest_amount' => $allocation?->interest_amount,
            'overdue_interest_amount' => $allocation?->overdue_interest_amount,
            'is_direct_payment' => $isDirectPayment,
            'excess_treatment' => $payment->excess_treatment,
            'overpayment_amount' => (string) $payment->overpayment_amount,
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'payment_method' => $method ? [
                'id' => $method->id,
                'code' => $method->code,
                'name' => $method->name,
                'type' => $method->type,
            ] : null,
            'financial_account' => $account ? [
                'id' => $account->id,
                'name' => $account->name,
                'kind' => $account->kind,
                'currency' => $account->currency,
                'masked_account_number' => $account->masked_account_number,
            ] : null,
            'financial_institution' => $institution ? [
                'id' => $institution->id,
                'code' => $institution->code,
                'name' => $institution->name,
                'type' => $institution->type,
            ] : null,
        ];
    }

    private function units(mixed $amount): int
    {
        return (int) round((float) $amount * 10000);
    }

    private function money(int $units): string
    {
        return number_format($units / 10000, 4, '.', '');
    }
}
