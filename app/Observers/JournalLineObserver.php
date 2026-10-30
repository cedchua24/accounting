<?php

namespace App\Observers;

use App\Models\JournalLine;
use App\Services\FinancialAccountBalanceService;

class JournalLineObserver
{
    public function __construct(private FinancialAccountBalanceService $balances) {}

    public function created(JournalLine $line): void
    {
        $this->balances->apply($line);
    }

    public function deleted(JournalLine $line): void
    {
        $this->balances->apply($line, true);
    }
}
