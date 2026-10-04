<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountAdjustment;
use App\Models\FinancialAccountApplication;
use App\Models\JournalLine;
use App\Models\PaymentObligation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancialAccountAdjustmentService
{
    public function create(array $data, ?int $userId): FinancialAccountAdjustment
    {
        return DB::transaction(function () use ($data, $userId) {
            $adjustment = FinancialAccountAdjustment::create([
                ...$data,
                'number' => 'ADJ-'.Str::upper((string) Str::ulid()),
                'created_by' => $userId,
            ]);

            return ! empty($data['post'])
                ? $this->post($adjustment, $userId)
                : $adjustment->load($this->relations());
        });
    }

    public function post(FinancialAccountAdjustment $adjustment, ?int $userId): FinancialAccountAdjustment
    {
        return DB::transaction(function () use ($adjustment, $userId) {
            $adjustment = FinancialAccountAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft balance adjustments can be posted.']);
            }

            $account = FinancialAccount::query()->lockForUpdate()
                ->with('chartAccount', 'applications')->findOrFail($adjustment->financial_account_id);
            $offset = ChartAccount::query()->lockForUpdate()->findOrFail($adjustment->adjustment_account_id);
            $obligation = $adjustment->payment_obligation_id
                ? PaymentObligation::query()->lockForUpdate()->findOrFail($adjustment->payment_obligation_id)
                : null;
            $this->assertAccounts($account, $offset, $adjustment->currency, $obligation, (string) $adjustment->actual_balance);

            $systemBalance = $obligation
                ? (string) $obligation->total_amount
                : (string) JournalLine::query()
                    ->where('chart_account_id', $account->chart_account_id)
                    ->whereHas('entry', fn ($entry) => $entry->whereDate('entry_date', '<=', $adjustment->adjustment_date))
                    ->selectRaw('COALESCE(SUM(debit - credit), 0) AS balance')->value('balance');
            $difference = bcsub((string) $adjustment->actual_balance, $systemBalance, 4);

            if (bccomp($difference, '0.0000', 4) !== 0) {
                $amount = ltrim($difference, '-');
                $increase = bccomp($difference, '0.0000', 4) === 1;
                $accountIsDebit = $account->kind === 'credit_card' ? ! $increase : $increase;
                $entry = $adjustment->journalEntries()->create([
                    'kind' => 'account_balance_adjustment',
                    'number' => 'JE-'.$adjustment->number,
                    'entry_date' => $adjustment->adjustment_date,
                    'memo' => $adjustment->reason,
                    'created_by' => $userId,
                ]);
                $entry->lines()->createMany([
                    [
                        'chart_account_id' => $account->chart_account_id,
                        'debit' => $accountIsDebit ? $amount : 0,
                        'credit' => $accountIsDebit ? 0 : $amount,
                        'description' => 'Balance reconciliation: '.$account->name,
                    ],
                    [
                        'chart_account_id' => $offset->id,
                        'debit' => $accountIsDebit ? 0 : $amount,
                        'credit' => $accountIsDebit ? $amount : 0,
                        'description' => 'Reconciliation difference: '.$adjustment->reason,
                    ],
                ]);
            }

            if ($obligation) {
                $obligation->update([
                    'adjustment_amount' => bcadd((string) $obligation->adjustment_amount, $difference, 4),
                ]);
                $this->refreshObligationStatus($obligation);
            }

            $adjustment->update([
                'system_balance_before' => $systemBalance,
                'adjustment_amount' => $difference,
                'status' => 'posted', 'posted_by' => $userId, 'posted_at' => now(),
            ]);

            return $adjustment->fresh($this->relations());
        });
    }

    public function void(FinancialAccountAdjustment $adjustment, string $reason, ?int $userId): FinancialAccountAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reason, $userId) {
            $adjustment = FinancialAccountAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'Only posted balance adjustments can be voided.']);
            }

            $obligation = $adjustment->payment_obligation_id
                ? PaymentObligation::query()->lockForUpdate()->findOrFail($adjustment->payment_obligation_id)
                : null;
            if ($obligation && $obligation->balanceAdjustments()
                ->where('status', 'posted')->where('id', '>', $adjustment->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Void newer posted adjustments for this statement first.']);
            }

            $original = $adjustment->journalEntries()->where('kind', 'account_balance_adjustment')->with('lines')->first();
            if ($original) {
                $reversal = $adjustment->journalEntries()->create([
                    'kind' => 'account_balance_adjustment_reversal',
                    'number' => 'VOID-'.$adjustment->number,
                    'entry_date' => now()->toDateString(),
                    'memo' => $reason,
                    'created_by' => $userId,
                ]);
                $reversal->lines()->createMany($original->lines->map(fn ($line) => [
                    'chart_account_id' => $line->chart_account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => 'Reversal: '.$line->description,
                ])->all());
            }

            $adjustment->update([
                'status' => 'voided', 'voided_by' => $userId,
                'voided_at' => now(), 'void_reason' => $reason,
            ]);
            if ($obligation) {
                $obligation->update([
                    'adjustment_amount' => bcsub((string) $obligation->adjustment_amount, (string) $adjustment->adjustment_amount, 4),
                ]);
                $this->refreshObligationStatus($obligation);
            }

            return $adjustment->fresh($this->relations());
        });
    }

    private function assertAccounts(FinancialAccount $account, ChartAccount $offset, string $currency, ?PaymentObligation $obligation, string $actualBalance): void
    {
        $validLedgerType = $account->kind === 'credit_card'
            ? $account->chartAccount?->type === 'liability'
            : $account->chartAccount?->type === 'asset';
        if (! $account->is_active || $account->currency !== $currency || ! $validLedgerType) {
            throw ValidationException::withMessages(['financial_account_id' => 'Select an active financial account with the correct currency and ledger type.']);
        }
        if ($account->applications->isNotEmpty()
            && ! $account->applications->contains('application', FinancialAccountApplication::BALANCE_ADJUSTMENT)) {
            throw ValidationException::withMessages(['financial_account_id' => 'This account is not enabled for balance adjustments.']);
        }
        if (! $offset->is_active || (int) $offset->id === (int) $account->chart_account_id) {
            throw ValidationException::withMessages(['adjustment_account_id' => 'Select a different active chart account for the reconciliation difference.']);
        }
        if ($account->kind === 'credit_card') {
            if (! $obligation || $obligation->type !== 'credit_card_statement'
                || (int) $obligation->financial_account_id !== (int) $account->id
                || $obligation->currency !== $currency || $obligation->status === 'voided') {
                throw ValidationException::withMessages(['payment_obligation_id' => 'Select an active statement belonging to this credit-card account.']);
            }
            if (bccomp((string) $obligation->paid_amount, $actualBalance, 4) === 1) {
                throw ValidationException::withMessages(['actual_balance' => 'The SOA balance cannot be lower than the amount already paid.']);
            }
        } elseif ($obligation) {
            throw ValidationException::withMessages(['payment_obligation_id' => 'A statement is only used when adjusting a credit-card account.']);
        }
    }

    private function refreshObligationStatus(PaymentObligation $obligation): void
    {
        $obligation->refresh();
        $paid = (float) $obligation->paid_amount;
        $total = (float) $obligation->total_amount;
        $status = $paid >= $total ? 'paid' : ($paid > 0 ? 'partially_paid' : ($obligation->due_date->isPast() ? 'overdue' : 'open'));
        $obligation->update(['status' => $status]);
    }

    private function relations(): array
    {
        return [
            'financialAccount.institution', 'financialAccount.chartAccount',
            'adjustmentAccount', 'paymentObligation', 'journalEntries.lines.chartAccount',
        ];
    }
}
