<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerRefund;
use App\Models\EndOfDaySettlement;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountAdjustment;
use App\Models\FinancialAccountTransfer;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PaymentObligation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const LIQUID_ACCOUNT_KINDS = [
        'cash',
        'bank_account',
        'checking_account',
        'e_wallet',
    ];

    public function totalCashBalance(Request $request)
    {
        $filters = $request->validate([
            'day_from' => ['nullable', 'date_format:Y-m-d'],
            'day_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:day_from'],
        ]);

        $accounts = FinancialAccount::query()
            ->whereIn('kind', self::LIQUID_ACCOUNT_KINDS)
            ->get(['id', 'chart_account_id', 'current_balance']);

        $dayFrom = $filters['day_from'] ?? null;
        $dayTo = $filters['day_to'] ?? null;
        $total = $dayTo
            ? $this->journalBalance($accounts->pluck('chart_account_id')->all(), $dayTo)
            : $this->cachedBalance($accounts->pluck('current_balance')->all());
        $opening = $dayFrom
            ? $this->journalBalance($accounts->pluck('chart_account_id')->all(), $dayFrom, false)
            : null;
        $change = $opening === null ? null : bcsub($total, $opening, 4);

        return response()->json([
            'total_cash_balance' => $total,
            'opening_cash_balance' => $opening,
            'net_change' => $change,
            'change_percentage' => $this->changePercentage($opening, $change),
            'day_from' => $dayFrom,
            'day_to' => $dayTo,
            'account_count' => $accounts->count(),
            'included_account_types' => self::LIQUID_ACCOUNT_KINDS,
        ]);
    }

    public function collections(Request $request)
    {
        $filters = $request->validate([
            'day_from' => ['nullable', 'date_format:Y-m-d'],
            'day_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:day_from'],
        ]);

        $dayFrom = $filters['day_from'] ?? null;
        $dayTo = $filters['day_to'] ?? null;
        $current = $this->collectionPeriod($dayFrom, $dayTo);
        $previousDayFrom = null;
        $previousDayTo = null;
        $previous = null;

        if ($dayFrom && $dayTo) {
            [$previousDayFrom, $previousDayTo] = $this->previousPeriod($dayFrom, $dayTo);
            $previous = $this->collectionPeriod($previousDayFrom, $previousDayTo);
        }

        $change = $previous === null
            ? null
            : bcsub($current['total'], $previous['total'], 4);

        return response()->json([
            'total_collections' => $current['total'],
            'collection_count' => $current['count'],
            'previous_period_collections' => $previous['total'] ?? null,
            'previous_period_collection_count' => $previous['count'] ?? null,
            'net_change' => $change,
            'change_percentage' => $this->changePercentage($previous['total'] ?? null, $change),
            'day_from' => $dayFrom,
            'day_to' => $dayTo,
            'previous_day_from' => $previousDayFrom,
            'previous_day_to' => $previousDayTo,
        ]);
    }

    public function payments(Request $request)
    {
        $filters = $request->validate([
            'day_from' => ['nullable', 'date_format:Y-m-d'],
            'day_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:day_from'],
        ]);

        $dayFrom = $filters['day_from'] ?? null;
        $dayTo = $filters['day_to'] ?? null;
        $current = $this->paymentPeriod('outflow', $dayFrom, $dayTo);
        $previousDayFrom = null;
        $previousDayTo = null;
        $previous = null;

        if ($dayFrom && $dayTo) {
            [$previousDayFrom, $previousDayTo] = $this->previousPeriod($dayFrom, $dayTo);
            $previous = $this->paymentPeriod('outflow', $previousDayFrom, $previousDayTo);
        }

        $change = $previous === null
            ? null
            : bcsub($current['total'], $previous['total'], 4);

        return response()->json([
            'total_payments' => $current['total'],
            'payment_count' => $current['count'],
            'previous_period_payments' => $previous['total'] ?? null,
            'previous_period_payment_count' => $previous['count'] ?? null,
            'net_change' => $change,
            'change_percentage' => $this->changePercentage($previous['total'] ?? null, $change),
            'day_from' => $dayFrom,
            'day_to' => $dayTo,
            'previous_day_from' => $previousDayFrom,
            'previous_day_to' => $previousDayTo,
        ]);
    }

    public function upcomingDues(Request $request)
    {
        $filters = $request->validate([
            'day_from' => ['nullable', 'date_format:Y-m-d'],
            'day_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:day_from'],
        ]);

        $dayFrom = $filters['day_from'] ?? null;
        $dayTo = $filters['day_to'] ?? null;
        $obligations = PaymentObligation::query()
            ->whereIn('status', ['open', 'partially_paid', 'overdue'])
            ->when($dayFrom, fn ($query, $date) => $query->whereDate('due_date', '>=', $date))
            ->when($dayTo, fn ($query, $date) => $query->whereDate('due_date', '<=', $date))
            ->withSum([
                'allocations as posted_allocated_amount' => fn ($query) => $query
                    ->whereHas('payment', fn ($payment) => $payment->where('status', 'posted')),
            ], 'amount')
            ->orderBy('due_date')
            ->get();

        $outstanding = $obligations->map(function (PaymentObligation $obligation) {
            $total = bcadd((string) $obligation->principal_amount, (string) $obligation->interest_amount, 4);
            $total = bcadd($total, (string) $obligation->fee_amount, 4);
            $total = bcadd($total, (string) $obligation->adjustment_amount, 4);
            $balance = bcsub($total, (string) ($obligation->posted_allocated_amount ?? 0), 4);

            return [
                'due_date' => $obligation->due_date->toDateString(),
                'balance' => bccomp($balance, '0.0000', 4) === 1 ? $balance : '0.0000',
            ];
        })->filter(fn (array $obligation) => bccomp($obligation['balance'], '0.0000', 4) === 1);

        return response()->json([
            'total_upcoming_dues' => $this->cachedBalance($outstanding->pluck('balance')->all()),
            'obligation_count' => $outstanding->count(),
            'nearest_due_date' => $outstanding->first()['due_date'] ?? null,
            'day_from' => $dayFrom,
            'day_to' => $dayTo,
        ]);
    }

    public function upcomingDuesList(Request $request)
    {
        $filters = $request->validate([
            'day_from' => ['nullable', 'date_format:Y-m-d'],
            'day_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:day_from'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $asOf = CarbonImmutable::createFromFormat(
            'Y-m-d',
            $filters['as_of'] ?? CarbonImmutable::today()->toDateString()
        )->startOfDay();
        $dayFrom = $filters['day_from'] ?? $asOf->toDateString();
        $dayTo = $filters['day_to'] ?? $asOf->addDays(14)->toDateString();
        $limit = (int) ($filters['limit'] ?? 3);

        $obligations = PaymentObligation::query()
            ->with(['financialAccount.institution', 'counterparty'])
            ->whereIn('status', ['open', 'partially_paid', 'overdue'])
            ->whereDate('due_date', '>=', $dayFrom)
            ->whereDate('due_date', '<=', $dayTo)
            ->withSum([
                'allocations as posted_allocated_amount' => fn ($query) => $query
                    ->whereHas('payment', fn ($payment) => $payment->where('status', 'posted')),
            ], 'amount')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(function (PaymentObligation $obligation) use ($asOf) {
                $balance = $this->obligationBalance($obligation);
                if (bccomp($balance, '0.0000', 4) !== 1) {
                    return null;
                }

                $dueDate = CarbonImmutable::parse($obligation->due_date)->startOfDay();
                $daysUntilDue = $asOf->diffInDays($dueDate, false);
                $vendor = $obligation->type === 'credit_card_statement'
                    ? ($obligation->financialAccount?->name ?? 'Credit card')
                    : ($obligation->counterparty?->name ?? $obligation->financialAccount?->name ?? 'Payment obligation');
                $reference = $obligation->type === 'credit_card_statement'
                    ? 'Statement • '.CarbonImmutable::parse($obligation->statement_date ?? $obligation->due_date)->format('M Y')
                    : ($obligation->source_reference ?: $obligation->number);

                return [
                    'id' => $obligation->id,
                    'vendor' => $vendor,
                    'ref' => $reference,
                    'due' => $dueDate->format('M j'),
                    'amount' => $balance,
                    'urgency' => $this->dueUrgency($daysUntilDue),
                    'number' => $obligation->number,
                    'type' => $obligation->type,
                    'status' => $obligation->status,
                    'currency' => $obligation->currency,
                    'due_date' => $dueDate->toDateString(),
                    'due_day' => $dueDate->format('j'),
                    'due_month' => strtoupper($dueDate->format('M')),
                    'amount_due' => $balance,
                    'days_until_due' => $daysUntilDue,
                    'is_overdue' => $daysUntilDue < 0,
                    'financial_account_id' => $obligation->financial_account_id,
                    'counterparty_id' => $obligation->counterparty_id,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'data' => $obligations->take($limit)->values(),
            'total' => $obligations->count(),
            'total_amount_due' => $this->cachedBalance($obligations->pluck('amount_due')->all()),
            'day_from' => $dayFrom,
            'day_to' => $dayTo,
            'as_of' => $asOf->toDateString(),
            'limit' => $limit,
        ]);
    }

    public function cashFlow(Request $request)
    {
        $filters = $request->validate([
            'day_from' => ['nullable', 'date_format:Y-m-d'],
            'day_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:day_from'],
        ]);

        if (! isset($filters['day_from'], $filters['day_to'])) {
            $singleDate = $filters['day_from'] ?? $filters['day_to'] ?? null;
            $dayTo = $singleDate
                ? CarbonImmutable::createFromFormat('Y-m-d', $singleDate)->startOfDay()
                : CarbonImmutable::today();
            $dayFrom = $singleDate ? $dayTo : $dayTo->subDays(29);
        } else {
            $dayFrom = CarbonImmutable::createFromFormat('Y-m-d', $filters['day_from'])->startOfDay();
            $dayTo = CarbonImmutable::createFromFormat('Y-m-d', $filters['day_to'])->startOfDay();
        }

        $entryFlows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('financial_accounts', 'financial_accounts.chart_account_id', '=', 'journal_lines.chart_account_id')
            ->whereIn('financial_accounts.kind', self::LIQUID_ACCOUNT_KINDS)
            ->whereBetween('journal_entries.entry_date', [$dayFrom->toDateString(), $dayTo->toDateString()])
            ->groupBy('journal_lines.journal_entry_id', 'journal_entries.entry_date')
            ->orderBy('journal_entries.entry_date')
            ->get([
                'journal_entries.entry_date as flow_date',
                DB::raw('COALESCE(SUM(journal_lines.debit), 0) AS liquid_debits'),
                DB::raw('COALESCE(SUM(journal_lines.credit), 0) AS liquid_credits'),
            ]);

        $daily = [];
        for ($date = $dayFrom; $date->lessThanOrEqualTo($dayTo); $date = $date->addDay()) {
            $daily[$date->toDateString()] = [
                'money_in' => '0.0000',
                'money_out' => '0.0000',
            ];
        }

        foreach ($entryFlows as $entryFlow) {
            $net = bcsub((string) $entryFlow->liquid_debits, (string) $entryFlow->liquid_credits, 4);
            $date = CarbonImmutable::parse((string) $entryFlow->flow_date)->toDateString();
            if (bccomp($net, '0.0000', 4) === 1) {
                $daily[$date]['money_in'] = bcadd($daily[$date]['money_in'], $net, 4);
            } elseif (bccomp($net, '0.0000', 4) === -1) {
                $daily[$date]['money_out'] = bcadd($daily[$date]['money_out'], bcsub('0.0000', $net, 4), 4);
            }
        }

        $moneyIn = '0.0000';
        $moneyOut = '0.0000';
        $cumulative = '0.0000';
        $points = [];
        foreach ($daily as $date => $flow) {
            $net = bcsub($flow['money_in'], $flow['money_out'], 4);
            $moneyIn = bcadd($moneyIn, $flow['money_in'], 4);
            $moneyOut = bcadd($moneyOut, $flow['money_out'], 4);
            $cumulative = bcadd($cumulative, $net, 4);
            $points[] = [
                'date' => $date,
                'money_in' => $flow['money_in'],
                'money_out' => $flow['money_out'],
                'net_cash_flow' => $net,
                'cumulative_net_cash_flow' => $cumulative,
            ];
        }

        return response()->json([
            'money_in' => $moneyIn,
            'money_out' => $moneyOut,
            'net_cash_flow' => bcsub($moneyIn, $moneyOut, 4),
            'day_from' => $dayFrom->toDateString(),
            'day_to' => $dayTo->toDateString(),
            'point_count' => count($points),
            'points' => $points,
        ]);
    }

    public function recentActivity(Request $request)
    {
        $filters = $request->validate([
            'day_from' => ['nullable', 'date_format:Y-m-d'],
            'day_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:day_from'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $dayFrom = $filters['day_from'] ?? null;
        $dayTo = $filters['day_to'] ?? null;
        $asOf = CarbonImmutable::createFromFormat(
            'Y-m-d',
            $filters['as_of'] ?? CarbonImmutable::today()->toDateString()
        )->startOfDay();
        $limit = (int) ($filters['limit'] ?? 4);

        $payments = Payment::query()
            ->with(['branch', 'salesInvoice', 'purchaseOrder', 'expense', 'lines.financialAccount'])
            ->when($dayFrom, fn ($query, $date) => $query->whereDate('payment_date', '>=', $date))
            ->when($dayTo, fn ($query, $date) => $query->whereDate('payment_date', '<=', $date))
            ->orderByRaw('COALESCE(posted_at, updated_at) DESC')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (Payment $payment) use ($asOf) {
                $reference = $payment->salesInvoice?->number
                    ?? $payment->purchaseOrder?->number
                    ?? $payment->expense?->number
                    ?? $payment->number;
                $account = $payment->lines->pluck('financialAccount.name')->filter()->unique()->join(', ');
                $amount = $payment->direction === 'inflow'
                    ? bcadd((string) $payment->amount, '0', 4)
                    : bcsub('0.0000', (string) $payment->amount, 4);

                return $this->activityItem(
                    'payment',
                    $payment->id,
                    $payment->direction === 'inflow' ? 'in' : 'out',
                    match ($payment->type) {
                        'customer' => 'Customer payment',
                        'purchase_order' => 'Supplier payment',
                        'expense' => 'Expense payment',
                        default => 'Account payment',
                    },
                    collect([$payment->branch?->name, $reference])->filter()->join(' · '),
                    $account,
                    $amount,
                    $payment->currency,
                    $payment->status,
                    $payment->payment_date,
                    $payment->posted_at ?? $payment->updated_at ?? $payment->created_at,
                    $asOf
                );
            });

        $transfers = FinancialAccountTransfer::query()
            ->with(['sourceAccount', 'destinationAccount'])
            ->when($dayFrom, fn ($query, $date) => $query->whereDate('transfer_date', '>=', $date))
            ->when($dayTo, fn ($query, $date) => $query->whereDate('transfer_date', '<=', $date))
            ->orderByRaw('COALESCE(posted_at, updated_at) DESC')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (FinancialAccountTransfer $transfer) => $this->activityItem(
                'account_transfer',
                $transfer->id,
                'transfer',
                'Account transfer',
                $transfer->reference ?: $transfer->number,
                collect([
                    $transfer->sourceAccount?->name,
                    $transfer->destination_type === 'external'
                        ? $transfer->recipient_name
                        : $transfer->destinationAccount?->name,
                ])->filter()->join(' → '),
                bcadd((string) $transfer->amount, '0', 4),
                $transfer->currency,
                $transfer->status,
                $transfer->transfer_date,
                $transfer->posted_at ?? $transfer->updated_at ?? $transfer->created_at,
                $asOf
            ));

        $refunds = CustomerRefund::query()
            ->with(['branch', 'financialAccount'])
            ->when($dayFrom, fn ($query, $date) => $query->whereDate('refund_date', '>=', $date))
            ->when($dayTo, fn ($query, $date) => $query->whereDate('refund_date', '<=', $date))
            ->orderByRaw('COALESCE(posted_at, updated_at) DESC')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CustomerRefund $refund) => $this->activityItem(
                'customer_refund',
                $refund->id,
                'out',
                'Customer refund',
                collect([$refund->branch?->name, $refund->reference ?: $refund->number])->filter()->join(' · '),
                $refund->financialAccount?->name ?? '',
                bcsub('0.0000', (string) $refund->amount, 4),
                $refund->currency,
                $refund->status,
                $refund->refund_date,
                $refund->posted_at ?? $refund->updated_at ?? $refund->created_at,
                $asOf
            ));

        $adjustments = FinancialAccountAdjustment::query()
            ->with('financialAccount')
            ->when($dayFrom, fn ($query, $date) => $query->whereDate('adjustment_date', '>=', $date))
            ->when($dayTo, fn ($query, $date) => $query->whereDate('adjustment_date', '<=', $date))
            ->orderByRaw('COALESCE(posted_at, updated_at) DESC')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (FinancialAccountAdjustment $adjustment) use ($asOf) {
                $amount = bcadd((string) $adjustment->adjustment_amount, '0', 4);

                return $this->activityItem(
                    'account_adjustment',
                    $adjustment->id,
                    bccomp($amount, '0.0000', 4) >= 0 ? 'in' : 'out',
                    'Account adjustment',
                    $adjustment->reference ?: $adjustment->number,
                    $adjustment->financialAccount?->name ?? '',
                    $amount,
                    $adjustment->currency,
                    $adjustment->status,
                    $adjustment->adjustment_date,
                    $adjustment->posted_at ?? $adjustment->updated_at ?? $adjustment->created_at,
                    $asOf
                );
            });

        $settlements = EndOfDaySettlement::query()
            ->with(['branches', 'destinationAccount'])
            ->when($dayFrom, fn ($query, $date) => $query->whereDate('business_date', '>=', $date))
            ->when($dayTo, fn ($query, $date) => $query->whereDate('business_date', '<=', $date))
            ->orderByRaw('COALESCE(posted_at, updated_at) DESC')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (EndOfDaySettlement $settlement) => $this->activityItem(
                'end_of_day_settlement',
                $settlement->id,
                'transfer',
                'End-of-day settlement',
                $settlement->number,
                collect([$settlement->branches->pluck('name')->join(', '), $settlement->destinationAccount?->name])->filter()->join(' → '),
                bcadd((string) $settlement->transfer_amount, '0', 4),
                $settlement->currency,
                $settlement->status,
                $settlement->business_date,
                $settlement->posted_at ?? $settlement->updated_at ?? $settlement->created_at,
                $asOf
            ));

        $activities = collect([$payments, $transfers, $refunds, $adjustments, $settlements])
            ->flatten(1)
            ->sortByDesc('occurred_at')
            ->take($limit)
            ->values();

        return response()->json([
            'data' => $activities,
            'count' => $activities->count(),
            'day_from' => $dayFrom,
            'day_to' => $dayTo,
            'as_of' => $asOf->toDateString(),
            'limit' => $limit,
        ]);
    }

    private function cachedBalance(array $balances): string
    {
        return array_reduce(
            $balances,
            fn (string $total, $balance) => bcadd($total, (string) $balance, 4),
            '0.0000'
        );
    }

    private function journalBalance(array $chartAccountIds, string $date, bool $inclusive = true): string
    {
        if ($chartAccountIds === []) {
            return '0.0000';
        }

        $totals = JournalLine::query()
            ->whereIn('chart_account_id', $chartAccountIds)
            ->whereHas('entry', fn ($query) => $query->whereDate(
                'entry_date',
                $inclusive ? '<=' : '<',
                $date
            ))
            ->selectRaw('COALESCE(SUM(debit), 0) AS debits, COALESCE(SUM(credit), 0) AS credits')
            ->first();

        return bcsub((string) $totals->debits, (string) $totals->credits, 4);
    }

    private function collectionPeriod(?string $dayFrom, ?string $dayTo): array
    {
        return $this->paymentPeriod('inflow', $dayFrom, $dayTo, 'customer');
    }

    private function paymentPeriod(string $direction, ?string $dayFrom, ?string $dayTo, ?string $type = null): array
    {
        $query = Payment::query()
            ->where('direction', $direction)
            ->where('status', 'posted')
            ->when($type, fn ($builder, $paymentType) => $builder->where('type', $paymentType))
            ->when($dayFrom, fn ($builder, $date) => $builder->whereDate('payment_date', '>=', $date))
            ->when($dayTo, fn ($builder, $date) => $builder->whereDate('payment_date', '<=', $date));

        return [
            'total' => number_format((float) (clone $query)->sum('amount'), 4, '.', ''),
            'count' => $query->count(),
        ];
    }

    private function obligationBalance(PaymentObligation $obligation): string
    {
        $total = bcadd((string) $obligation->principal_amount, (string) $obligation->interest_amount, 4);
        $total = bcadd($total, (string) $obligation->fee_amount, 4);
        $total = bcadd($total, (string) $obligation->adjustment_amount, 4);
        $balance = bcsub($total, (string) ($obligation->posted_allocated_amount ?? 0), 4);

        return bccomp($balance, '0.0000', 4) === 1 ? $balance : '0.0000';
    }

    private function dueUrgency(int $daysUntilDue): string
    {
        if ($daysUntilDue < 0) {
            $days = abs($daysUntilDue);

            return $days === 1 ? '1 day overdue' : "{$days} days overdue";
        }

        if ($daysUntilDue === 0) {
            return 'Due today';
        }

        return $daysUntilDue === 1 ? 'Due in 1 day' : "Due in {$daysUntilDue} days";
    }

    private function activityItem(
        string $sourceType,
        int $sourceId,
        string $type,
        string $title,
        string $meta,
        string $account,
        string $amount,
        string $currency,
        string $status,
        $activityDate,
        $occurredAt,
        CarbonImmutable $asOf
    ): array {
        $occurred = CarbonImmutable::parse($occurredAt ?? $activityDate);

        return [
            'id' => "{$sourceType}:{$sourceId}",
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'type' => $type,
            'title' => $title,
            'meta' => $meta,
            'account' => $account,
            'amount' => $amount,
            'currency' => $currency,
            'time' => $this->activityTime($occurred, $asOf),
            'status' => ucfirst($status),
            'activity_date' => CarbonImmutable::parse($activityDate)->toDateString(),
            'occurred_at' => $occurred->toIso8601String(),
        ];
    }

    private function activityTime(CarbonImmutable $occurredAt, CarbonImmutable $asOf): string
    {
        if ($occurredAt->isSameDay($asOf)) {
            return $occurredAt->format('g:i A');
        }

        if ($occurredAt->isSameDay($asOf->subDay())) {
            return 'Yesterday';
        }

        return $occurredAt->format('M j');
    }

    private function previousPeriod(string $dayFrom, string $dayTo): array
    {
        $from = CarbonImmutable::createFromFormat('Y-m-d', $dayFrom)->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $dayTo)->startOfDay();
        $periodDays = $from->diffInDays($to) + 1;

        return [
            $from->subDays($periodDays)->toDateString(),
            $from->subDay()->toDateString(),
        ];
    }

    private function changePercentage(?string $opening, ?string $change): ?string
    {
        if ($opening === null || $change === null || bccomp($opening, '0.0000', 4) === 0) {
            return null;
        }

        return bcdiv(bcmul($change, '100', 6), $opening, 2);
    }
}
