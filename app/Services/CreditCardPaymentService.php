<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentObligation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreditCardPaymentService
{
    public function __construct(private PaymentPostingService $postingService) {}

    public function pay(FinancialAccount $creditCard, array $data, ?int $userId): array
    {
        return DB::transaction(function () use ($creditCard, $data, $userId) {
            $creditCard = FinancialAccount::query()->with('chartAccount')->lockForUpdate()->findOrFail($creditCard->id);
            $fundingAccount = FinancialAccount::query()->with('chartAccount')->lockForUpdate()->findOrFail($data['funding_account_id']);
            $method = PaymentMethod::query()->lockForUpdate()->findOrFail($data['payment_method_id']);
            $this->assertSelection($creditCard, $fundingAccount, $method, $data);
            $this->assertFundingBalance($fundingAccount, (string) $data['amount']);

            $statementQuery = PaymentObligation::query()
                ->where('type', 'credit_card_statement')
                ->where('financial_account_id', $creditCard->id)
                ->whereNotIn('status', ['paid', 'voided'])
                ->orderBy('due_date')
                ->orderBy('id');

            $primaryStatement = null;
            if ($data['statement_id'] ?? null) {
                $primaryStatement = (clone $statementQuery)
                    ->whereKey($data['statement_id'])
                    ->lockForUpdate()
                    ->first();
                if (! $primaryStatement) {
                    throw ValidationException::withMessages([
                        'statement_id' => 'Select an unpaid statement belonging to this credit card.',
                    ]);
                }

                $laterStatements = (clone $statementQuery)
                    ->where(fn ($query) => $query
                        ->whereDate('due_date', '>', $primaryStatement->due_date)
                        ->orWhere(fn ($sameDate) => $sameDate
                            ->whereDate('due_date', $primaryStatement->due_date)
                            ->where('id', '>', $primaryStatement->id)))
                    ->lockForUpdate()
                    ->get();
                $statements = collect([$primaryStatement])->concat($laterStatements);
            } else {
                $statements = $statementQuery->lockForUpdate()->get();
            }

            $remaining = bcadd((string) $data['amount'], '0', 4);
            $amountDueBefore = $primaryStatement ? $this->statementBalance($primaryStatement) : '0.0000';
            $primaryAllocatedAmount = '0.0000';
            $totalAllocatedAmount = '0.0000';
            $carriedForwardAmount = '0.0000';
            $allocations = [];
            foreach ($statements as $statement) {
                $balance = $this->statementBalance($statement);
                if (! $primaryStatement) {
                    $amountDueBefore = bcadd($amountDueBefore, $balance, 4);
                }
                if (bccomp($remaining, '0.0000', 4) !== 1 || bccomp($balance, '0.0000', 4) !== 1) {
                    continue;
                }

                $allocated = bccomp($remaining, $balance, 4) === 1 ? $balance : $remaining;
                $allocations[] = [
                    'payment_obligation_id' => $statement->id,
                    'amount' => $allocated,
                ];
                $remaining = bcsub($remaining, $allocated, 4);
                $totalAllocatedAmount = bcadd($totalAllocatedAmount, $allocated, 4);
                if ($primaryStatement && (int) $statement->id !== (int) $primaryStatement->id) {
                    $carriedForwardAmount = bcadd($carriedForwardAmount, $allocated, 4);
                } else {
                    $primaryAllocatedAmount = bcadd($primaryAllocatedAmount, $allocated, 4);
                }
            }
            if (! $primaryStatement) {
                $primaryAllocatedAmount = $totalAllocatedAmount;
            }

            $amount = bcadd((string) $data['amount'], '0', 4);
            $paymentMode = bccomp($amount, $amountDueBefore, 4) === -1
                ? 'partial'
                : (bccomp($amount, $amountDueBefore, 4) === 0 ? 'exact' : 'overpayment');
            $overpaymentAmount = bccomp($amount, $amountDueBefore, 4) === 1
                ? bcsub($amount, $amountDueBefore, 4)
                : '0.0000';
            $amountDueAfter = bcsub($amountDueBefore, $primaryAllocatedAmount, 4);

            $payment = Payment::create([
                'number' => 'PAY-'.Str::upper((string) Str::ulid()),
                'branch_id' => $data['branch_id'],
                'type' => 'chart_account',
                'direction' => 'outflow',
                'offset_account_id' => $creditCard->chart_account_id,
                'payment_date' => $data['payment_date'],
                'currency' => $creditCard->currency,
                'amount' => $amount,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'memo' => $data['memo'] ?? 'Credit card payment for '.$creditCard->name,
                'created_by' => $userId,
            ]);
            $payment->lines()->create([
                'financial_account_id' => $fundingAccount->id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
            ]);
            $payment->allocations()->createMany($allocations);
            $payment = $this->postingService->post($payment, $userId);

            return [
                'payment_mode' => $paymentMode,
                'amount' => $amount,
                'amount_due_before' => $amountDueBefore,
                'allocated_amount' => $primaryAllocatedAmount,
                'total_allocated_amount' => $totalAllocatedAmount,
                'overpayment_amount' => $overpaymentAmount,
                'carried_forward_amount' => $carriedForwardAmount,
                'unapplied_credit_amount' => $remaining,
                'amount_due_after' => bccomp($amountDueAfter, '0.0000', 4) === 1 ? $amountDueAfter : '0.0000',
                'payment' => $payment,
            ];
        });
    }

    private function assertSelection(
        FinancialAccount $creditCard,
        FinancialAccount $fundingAccount,
        PaymentMethod $method,
        array $data,
    ): void {
        if ($creditCard->kind !== 'credit_card' || ! $creditCard->is_active) {
            throw ValidationException::withMessages(['credit_card' => 'Select an active credit-card account.']);
        }
        if (! $creditCard->isAvailableToBranch((int) $data['branch_id'])) {
            throw ValidationException::withMessages(['branch_id' => 'This credit card is not available to the selected branch.']);
        }

        if (! $fundingAccount->is_active
            || ! $fundingAccount->usable_for_payments
            || $fundingAccount->currency !== $creditCard->currency
            || ! $fundingAccount->isAvailableToBranch((int) $data['branch_id'])
            || $fundingAccount->chartAccount?->type !== 'asset'
            || ! in_array($fundingAccount->kind, ['cash', 'bank_account', 'checking_account', 'e_wallet'], true)) {
            throw ValidationException::withMessages([
                'funding_account_id' => 'Select an active cash, bank, checking, or e-wallet account that is available to this branch and enabled for payments.',
            ]);
        }

        $allowedKinds = $method->compatibleFundingAccountKinds();
        if (! $method->is_active || $method->creates_obligation || ! in_array($fundingAccount->kind, $allowedKinds, true)) {
            throw ValidationException::withMessages([
                'payment_method_id' => 'The payment method is incompatible with the selected funding account.',
            ]);
        }
    }

    private function assertFundingBalance(FinancialAccount $fundingAccount, string $amount): void
    {
        $required = bcadd($amount, '0', 4);
        if (bccomp((string) $fundingAccount->available_balance, $required, 4) === -1) {
            $available = bcadd((string) $fundingAccount->available_balance, '0', 4);
            $shortfall = bcsub($required, $available, 4);
            throw ValidationException::withMessages([
                'amount' => "Funding account {$fundingAccount->id} ({$fundingAccount->name}) has {$fundingAccount->currency} {$available} available, but {$fundingAccount->currency} {$required} is required. Reconcile the funding account or select another account. Shortfall: {$fundingAccount->currency} {$shortfall}.",
                'funding_account_id' => "Reconcile this account at /api/financial-accounts/{$fundingAccount->id}/adjustments before retrying the payment.",
            ]);
        }
    }

    private function statementBalance(PaymentObligation $statement): string
    {
        $total = bcadd(
            bcadd((string) $statement->principal_amount, (string) $statement->interest_amount, 4),
            bcadd((string) $statement->fee_amount, (string) $statement->adjustment_amount, 4),
            4
        );
        $paid = (string) $statement->allocations()
            ->whereHas('payment', fn ($query) => $query->where('status', 'posted'))
            ->sum('amount');
        $balance = bcsub($total, $paid, 4);

        return bccomp($balance, '0.0000', 4) === 1 ? $balance : '0.0000';
    }
}
