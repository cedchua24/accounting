<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function index(Request $request)
    {
        return JournalEntry::with('payment', 'loanPayment.loan', 'customerRefund', 'interbranchOrder', 'endOfDaySettlement', 'financialAccountTransfer', 'financialAccountAdjustment', 'lines.chartAccount', 'lines.branch')
            ->when($request->from, fn ($q, $date) => $q->whereDate('entry_date', '>=', $date))
            ->when($request->to, fn ($q, $date) => $q->whereDate('entry_date', '<=', $date))
            ->latest('entry_date')->latest('id')->paginate();
    }

    public function show(JournalEntry $journalEntry)
    {
        return $journalEntry->load('payment', 'loanPayment.loan', 'customerRefund', 'interbranchOrder', 'endOfDaySettlement', 'financialAccountTransfer', 'financialAccountAdjustment', 'lines.chartAccount', 'lines.branch');
    }
}
