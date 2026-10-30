<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\JournalLine;

class FinancialAccountBalanceService
{
    public function apply(JournalLine $line, bool $reverse = false): void
    {
        $account = FinancialAccount::query()
            ->where('chart_account_id', $line->chart_account_id)
            ->lockForUpdate()
            ->first();
        if (! $account) {
            return;
        }

        $effect = $account->kind === 'credit_card'
            ? bcsub((string) $line->credit, (string) $line->debit, 4)
            : bcsub((string) $line->debit, (string) $line->credit, 4);
        if ($reverse) {
            $effect = bcsub('0', $effect, 4);
        }

        $account->forceFill([
            'current_balance' => bcadd((string) $account->current_balance, $effect, 4),
            'balance_updated_at' => now(),
        ])->save();
    }

    public function rebuildAll(): int
    {
        $count = 0;
        FinancialAccount::query()->orderBy('id')->chunkById(100, function ($accounts) use (&$count) {
            foreach ($accounts as $account) {
                $net = (string) JournalLine::query()
                    ->where('chart_account_id', $account->chart_account_id)
                    ->selectRaw('COALESCE(SUM(debit - credit), 0) AS balance')
                    ->value('balance');
                $current = $account->kind === 'credit_card' ? bcsub('0', $net, 4) : bcadd($net, '0', 4);
                $account->forceFill([
                    'current_balance' => $current,
                    'balance_updated_at' => now(),
                ])->save();
                $count++;
            }
        });

        return $count;
    }
}
