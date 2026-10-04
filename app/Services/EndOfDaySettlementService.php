<?php

namespace App\Services;

use App\Models\EndOfDaySettlement;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\PaymentLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EndOfDaySettlementService
{
    public function create(array $data, ?int $userId): EndOfDaySettlement
    {
        return DB::transaction(function () use ($data, $userId) {
            $branchIds = collect($data['branch_ids'])->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $destination = FinancialAccount::query()->lockForUpdate()->findOrFail($data['destination_financial_account_id']);
            $this->assertDestination($destination, $branchIds->all(), $data['currency']);

            $lines = PaymentLine::query()
                ->whereHas('payment', fn ($query) => $query
                    ->where('status', 'posted')
                    ->where('type', 'customer')
                    ->where('direction', 'inflow')
                    ->whereDate('payment_date', $data['business_date'])
                    ->where('currency', $data['currency'])
                    ->whereIn('branch_id', $branchIds))
                ->whereDoesntHave('settlementItems.settlement', fn ($query) => $query->whereIn('status', ['draft', 'posted']))
                ->with('payment', 'financialAccount')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['business_date' => 'No unsettled posted customer payments were found for the selected branches and date.']);
            }

            $gross = $lines->reduce(fn ($total, $line) => bcadd($total, (string) $line->amount, 4), '0.0000');
            $transfer = $lines->reduce(fn ($total, $line) => (int) $line->financial_account_id === (int) $destination->id
                ? $total
                : bcadd($total, (string) $line->amount, 4), '0.0000');

            $settlement = EndOfDaySettlement::create([
                'number' => 'EOD-'.Str::upper((string) Str::ulid()),
                'business_date' => $data['business_date'],
                'destination_financial_account_id' => $destination->id,
                'currency' => $data['currency'],
                'gross_collection_amount' => $gross,
                'transfer_amount' => $transfer,
                'memo' => $data['memo'] ?? null,
                'created_by' => $userId,
            ]);
            $settlement->branches()->attach($branchIds->all());
            $settlement->items()->createMany($lines->map(fn ($line) => [
                'branch_id' => $line->payment->branch_id,
                'payment_line_id' => $line->id,
                'source_financial_account_id' => $line->financial_account_id,
                'amount' => $line->amount,
                'transfer_amount' => (int) $line->financial_account_id === (int) $destination->id ? 0 : $line->amount,
            ])->all());

            return $settlement->fresh($this->relations());
        });
    }

    public function post(EndOfDaySettlement $settlement, ?int $userId): EndOfDaySettlement
    {
        return DB::transaction(function () use ($settlement, $userId) {
            $settlement = EndOfDaySettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($settlement->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft end-of-day settlements can be posted.']);
            }

            $settlement->load('branches', 'destinationAccount.applications', 'items.paymentLine.payment', 'items.sourceAccount.chartAccount');
            $branchIds = $settlement->branches->modelKeys();
            $this->assertDestination($settlement->destinationAccount, $branchIds, $settlement->currency);
            foreach ($settlement->items as $item) {
                $payment = $item->paymentLine->payment;
                if ($payment->status !== 'posted'
                    || $payment->type !== 'customer'
                    || $payment->direction !== 'inflow'
                    || $payment->currency !== $settlement->currency
                    || $payment->payment_date->toDateString() !== $settlement->business_date->toDateString()
                    || ! in_array((int) $payment->branch_id, $branchIds, true)
                    || (int) $item->branch_id !== (int) $payment->branch_id
                    || (int) $item->source_financial_account_id !== (int) $item->paymentLine->financial_account_id
                    || bccomp((string) $item->amount, (string) $item->paymentLine->amount, 4) !== 0) {
                    throw ValidationException::withMessages(['items' => 'A customer payment in this batch is no longer eligible. Recreate the draft settlement.']);
                }
            }

            if (bccomp((string) $settlement->transfer_amount, '0.0000', 4) === 1) {
                $entry = $settlement->journalEntries()->create([
                    'kind' => 'end_of_day_settlement',
                    'number' => 'JE-'.$settlement->number,
                    'entry_date' => $settlement->business_date,
                    'memo' => $settlement->memo ?: 'End-of-day collection settlement',
                    'created_by' => $userId,
                ]);
                $journalLines = [];
                $transferItems = $settlement->items->filter(fn ($item) => bccomp((string) $item->transfer_amount, '0.0000', 4) === 1);
                foreach ($transferItems->groupBy('branch_id') as $branchId => $items) {
                    $branchTotal = $items->reduce(fn ($total, $item) => bcadd($total, (string) $item->transfer_amount, 4), '0.0000');
                    $journalLines[] = [
                        'branch_id' => $branchId,
                        'chart_account_id' => $settlement->destinationAccount->chart_account_id,
                        'debit' => $branchTotal,
                        'credit' => 0,
                        'description' => 'End-of-day deposit to '.$settlement->destinationAccount->name,
                    ];
                    foreach ($items->groupBy('source_financial_account_id') as $sourceItems) {
                        $source = $sourceItems->first()->sourceAccount;
                        $amount = $sourceItems->reduce(fn ($total, $item) => bcadd($total, (string) $item->transfer_amount, 4), '0.0000');
                        $journalLines[] = [
                            'branch_id' => $branchId,
                            'chart_account_id' => $source->chart_account_id,
                            'debit' => 0,
                            'credit' => $amount,
                            'description' => 'End-of-day transfer from '.$source->name,
                        ];
                    }
                }
                $entry->lines()->createMany($journalLines);
            }

            $settlement->update(['status' => 'posted', 'posted_by' => $userId, 'posted_at' => now()]);

            return $settlement->fresh($this->relations());
        });
    }

    public function void(EndOfDaySettlement $settlement, string $reason, ?int $userId): EndOfDaySettlement
    {
        return DB::transaction(function () use ($settlement, $reason, $userId) {
            $settlement = EndOfDaySettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($settlement->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'Only posted end-of-day settlements can be voided.']);
            }

            $original = $settlement->journalEntries()->where('kind', 'end_of_day_settlement')->with('lines')->first();
            if ($original) {
                $reversal = $settlement->journalEntries()->create([
                    'kind' => 'end_of_day_reversal',
                    'number' => 'VOID-'.$settlement->number,
                    'entry_date' => now()->toDateString(),
                    'memo' => $reason,
                    'created_by' => $userId,
                ]);
                $reversal->lines()->createMany($original->lines->map(fn ($line) => [
                    'branch_id' => $line->branch_id,
                    'chart_account_id' => $line->chart_account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => 'Reversal: '.$line->description,
                ])->all());
            }

            $settlement->update(['status' => 'voided', 'voided_by' => $userId, 'voided_at' => now(), 'void_reason' => $reason]);

            return $settlement->fresh($this->relations());
        });
    }

    private function assertDestination(FinancialAccount $destination, array $branchIds, string $currency): void
    {
        if (! $destination->is_active
            || $destination->currency !== $currency
            || $destination->kind === 'credit_card'
            || ! $destination->usable_for_receipts
            || $destination->chartAccount?->type !== 'asset') {
            throw ValidationException::withMessages(['destination_financial_account_id' => 'The destination must be an active receipt-enabled asset account in the settlement currency.']);
        }
        if (! $destination->applications()->where('application', FinancialAccountApplication::END_OF_DAY_SETTLEMENT)->exists()) {
            throw ValidationException::withMessages(['destination_financial_account_id' => 'The destination account is not enabled for end-of-day settlement.']);
        }
        foreach ($branchIds as $branchId) {
            if (! $destination->isAvailableToBranch((int) $branchId)) {
                throw ValidationException::withMessages(['destination_financial_account_id' => "The destination account is not available to branch {$branchId}."]);
            }
        }
    }

    private function relations(): array
    {
        return [
            'branches', 'destinationAccount.institution', 'destinationAccount.chartAccount',
            'items.branch', 'items.sourceAccount.institution', 'items.paymentLine.payment',
            'journalEntries.lines.chartAccount',
        ];
    }
}
