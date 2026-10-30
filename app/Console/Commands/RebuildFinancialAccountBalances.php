<?php

namespace App\Console\Commands;

use App\Services\FinancialAccountBalanceService;
use Illuminate\Console\Command;

class RebuildFinancialAccountBalances extends Command
{
    protected $signature = 'accounting:rebuild-financial-account-balances';

    protected $description = 'Rebuild cached financial-account balances from journal lines';

    public function handle(FinancialAccountBalanceService $balances): int
    {
        $count = $balances->rebuildAll();
        $this->info("Rebuilt balances for {$count} financial accounts.");

        return self::SUCCESS;
    }
}
