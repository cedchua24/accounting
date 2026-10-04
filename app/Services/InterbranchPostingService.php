<?php

namespace App\Services;

use App\Models\InterbranchOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InterbranchPostingService
{
    public function post(InterbranchOrder $order, ?int $userId): InterbranchOrder
    {
        return DB::transaction(function () use ($order, $userId) {
            $order = InterbranchOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft interbranch orders can be posted.']);
            }

            $order->load(['sourceBranch.inventoryAccount', 'sourceBranch.dueFromAccount', 'destinationBranch.inventoryAccount', 'destinationBranch.dueToAccount']);
            if (! $order->sourceBranch->is_active || ! $order->destinationBranch->is_active) {
                throw ValidationException::withMessages(['branch' => 'Both branches must be active.']);
            }

            $entry = $order->journalEntries()->create([
                'kind' => 'interbranch_transfer',
                'number' => "IBO-{$order->number}",
                'entry_date' => $order->order_date,
                'memo' => $order->memo,
                'created_by' => $userId,
            ]);

            $amount = $order->amount;
            if ($order->settlement_mode === 'inventory_transfer') {
                $entry->lines()->createMany([
                    ['branch_id' => $order->destination_branch_id, 'chart_account_id' => $order->destinationBranch->inventory_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Inventory received from '.$order->sourceBranch->name],
                    ['branch_id' => $order->source_branch_id, 'chart_account_id' => $order->sourceBranch->inventory_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Inventory transferred to '.$order->destinationBranch->name],
                ]);
            } else {
                $entry->lines()->createMany([
                    ['branch_id' => $order->destination_branch_id, 'chart_account_id' => $order->destinationBranch->inventory_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Inventory received from '.$order->sourceBranch->name],
                    ['branch_id' => $order->destination_branch_id, 'chart_account_id' => $order->destinationBranch->due_to_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Due to '.$order->sourceBranch->name],
                    ['branch_id' => $order->source_branch_id, 'chart_account_id' => $order->sourceBranch->due_from_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Due from '.$order->destinationBranch->name],
                    ['branch_id' => $order->source_branch_id, 'chart_account_id' => $order->sourceBranch->inventory_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Inventory transferred to '.$order->destinationBranch->name],
                ]);
            }

            $order->update(['status' => 'posted', 'posted_by' => $userId, 'posted_at' => now()]);

            return $order->fresh($this->relations());
        });
    }

    public function void(InterbranchOrder $order, string $reason, ?int $userId): InterbranchOrder
    {
        return DB::transaction(function () use ($order, $reason, $userId) {
            $order = InterbranchOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'Only posted interbranch orders can be voided.']);
            }

            $original = $order->journalEntries()->where('kind', 'interbranch_transfer')->with('lines')->firstOrFail();
            $reversal = $order->journalEntries()->create([
                'kind' => 'interbranch_reversal',
                'number' => "VOID-IBO-{$order->number}",
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

            $order->update(['status' => 'voided', 'voided_by' => $userId, 'voided_at' => now(), 'void_reason' => $reason]);

            return $order->fresh($this->relations());
        });
    }

    private function relations(): array
    {
        return ['sourceBranch', 'destinationBranch', 'journalEntries.lines.chartAccount', 'journalEntries.lines.branch'];
    }
}
