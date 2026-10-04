<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\FinancialAccountTransfer;
use App\Models\JournalLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancialAccountTransferService
{
    public function create(array $data, ?int $userId): FinancialAccountTransfer
    {
        return DB::transaction(function () use ($data, $userId) {
            $transfer = FinancialAccountTransfer::create([
                ...$data,
                'number' => 'TRF-'.Str::upper((string) Str::ulid()),
                'created_by' => $userId,
            ]);

            return ! empty($data['post'])
                ? $this->post($transfer, $userId)
                : $transfer->load($this->relations());
        });
    }

    public function post(FinancialAccountTransfer $transfer, ?int $userId): FinancialAccountTransfer
    {
        return DB::transaction(function () use ($transfer, $userId) {
            $transfer = FinancialAccountTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft account transfers can be posted.']);
            }

            $accounts = FinancialAccount::query()
                ->whereKey([$transfer->source_financial_account_id, $transfer->destination_financial_account_id])
                ->orderBy('id')->lockForUpdate()->with('chartAccount', 'applications')->get()->keyBy('id');
            $source = $accounts->get($transfer->source_financial_account_id);
            $destination = $accounts->get($transfer->destination_financial_account_id);
            $this->assertAccount($source, $transfer->currency, true);
            $this->assertAccount($destination, $transfer->currency, false);

            $available = JournalLine::query()->where('chart_account_id', $source->chart_account_id)
                ->selectRaw('COALESCE(SUM(debit - credit), 0) AS balance')->value('balance');
            if (bccomp((string) $available, (string) $transfer->amount, 4) === -1) {
                throw ValidationException::withMessages(['amount' => 'The transfer amount exceeds the source account balance.']);
            }

            $entry = $transfer->journalEntries()->create([
                'kind' => 'account_transfer',
                'number' => 'JE-'.$transfer->number,
                'entry_date' => $transfer->transfer_date,
                'memo' => $transfer->memo ?: "Transfer from {$source->name} to {$destination->name}",
                'created_by' => $userId,
            ]);
            $entry->lines()->createMany([
                [
                    'chart_account_id' => $destination->chart_account_id,
                    'debit' => $transfer->amount, 'credit' => 0,
                    'description' => 'Transfer received from '.$source->name,
                ],
                [
                    'chart_account_id' => $source->chart_account_id,
                    'debit' => 0, 'credit' => $transfer->amount,
                    'description' => 'Transfer sent to '.$destination->name,
                ],
            ]);
            $transfer->update(['status' => 'posted', 'posted_by' => $userId, 'posted_at' => now()]);

            return $transfer->fresh($this->relations());
        });
    }

    public function void(FinancialAccountTransfer $transfer, string $reason, ?int $userId): FinancialAccountTransfer
    {
        return DB::transaction(function () use ($transfer, $reason, $userId) {
            $transfer = FinancialAccountTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'Only posted account transfers can be voided.']);
            }

            $original = $transfer->journalEntries()->where('kind', 'account_transfer')->with('lines')->firstOrFail();
            $reversal = $transfer->journalEntries()->create([
                'kind' => 'account_transfer_reversal',
                'number' => 'VOID-'.$transfer->number,
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
            $transfer->update([
                'status' => 'voided', 'voided_by' => $userId,
                'voided_at' => now(), 'void_reason' => $reason,
            ]);

            return $transfer->fresh($this->relations());
        });
    }

    private function assertAccount(?FinancialAccount $account, string $currency, bool $source): void
    {
        $field = $source ? 'source_financial_account_id' : 'destination_financial_account_id';
        $directionAllowed = $source ? $account?->usable_for_payments : $account?->usable_for_receipts;
        if (! $account || ! $account->is_active || $account->currency !== $currency
            || $account->kind === 'credit_card' || $account->chartAccount?->type !== 'asset' || ! $directionAllowed) {
            throw ValidationException::withMessages([$field => 'Select an active, direction-enabled asset account in the transfer currency.']);
        }
        if ($account->applications->isNotEmpty()
            && ! $account->applications->contains('application', FinancialAccountApplication::ACCOUNT_TRANSFER)) {
            throw ValidationException::withMessages([$field => 'This account is not enabled for account transfers.']);
        }
    }

    private function relations(): array
    {
        return [
            'sourceAccount.institution', 'sourceAccount.chartAccount',
            'destinationAccount.institution', 'destinationAccount.chartAccount',
            'journalEntries.lines.chartAccount',
        ];
    }
}
