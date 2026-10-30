<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ExpenseLedgerController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'expense_type_id' => ['nullable', 'integer', 'exists:expense_types,id'],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'expense_item_id' => ['nullable', 'integer', 'exists:expense_items,id'],
            'chart_account_id' => ['nullable', 'integer', 'exists:chart_accounts,id'],
            'source_type' => ['nullable', 'string', 'max:60'],
            'approval_status' => ['nullable', Rule::in(['draft', 'pending', 'approved', 'rejected'])],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'partially_paid', 'paid', 'cancelled', 'posted'])],
            'system_generated' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $items = ExpenseItem::query()
            ->with('category.expenseType')
            ->get();
        $itemsByChart = $items->whereNotNull('chart_account_id')->groupBy('chart_account_id');

        $documentRows = $this->expenseDocuments($filters);
        $representedSources = Expense::query()
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->get(['source_type', 'source_id'])
            ->mapWithKeys(fn (Expense $expense) => [
                $expense->source_type.':'.$expense->source_id => true,
            ]);
        $journalRows = $this->journalExpenses($filters, $itemsByChart, $representedSources);

        $rows = $documentRows
            ->concat($journalRows)
            ->filter(fn (array $row) => $this->matchesNormalizedFilters($row, $filters))
            ->sortByDesc(fn (array $row) => $row['expense_date'].'|'.str_pad((string) $row['sort_id'], 20, '0', STR_PAD_LEFT))
            ->values();

        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginator);
    }

    private function expenseDocuments(array $filters): Collection
    {
        return Expense::query()
            ->with([
                'branch', 'payee', 'chartAccount', 'expenseItem.category.expenseType',
                'creator', 'approver', 'payments.lines.paymentMethod',
            ])
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '<=', $date))
            ->when($filters['branch_id'] ?? null, fn ($query, $id) => $query->where('branch_id', $id))
            ->when($filters['expense_type_id'] ?? null, fn ($query, $id) => $query->whereHas(
                'expenseItem.category', fn ($category) => $category->where('expense_type_id', $id)
            ))
            ->when($filters['expense_category_id'] ?? null, fn ($query, $id) => $query->whereHas(
                'expenseItem', fn ($item) => $item->where('expense_category_id', $id)
            ))
            ->when($filters['expense_item_id'] ?? null, fn ($query, $id) => $query->where('expense_item_id', $id))
            ->when($filters['chart_account_id'] ?? null, fn ($query, $id) => $query->where('chart_account_id', $id))
            ->when($filters['approval_status'] ?? null, fn ($query, $status) => $query->where('approval_status', $status))
            ->when(
                ($filters['payment_status'] ?? null) && $filters['payment_status'] !== 'posted',
                fn ($query) => $query->where('status', $filters['payment_status'])
            )
            ->when(array_key_exists('system_generated', $filters), fn ($query) => $query
                ->where('system_generated', (bool) $filters['system_generated']))
            ->when($filters['source_type'] ?? null, fn ($query, $source) => $source === 'manual_expense'
                ? $query->whereNull('source_type')
                : $query->where('source_type', $source))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($scope) use ($search) {
                $scope->where('number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhereHas('chartAccount', fn ($account) => $account
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"))
                    ->orWhereHas('expenseItem', fn ($item) => $item
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"));
            }))
            ->get()
            ->map(function (Expense $expense) {
                $item = $expense->expenseItem;
                $category = $item?->category;
                $type = $category?->expenseType;
                $sourceType = $expense->source_type
                    ?: ($expense->legacy_source ? 'legacy_expense' : 'manual_expense');

                return [
                    'register_id' => 'expense:'.$expense->id,
                    'record_type' => 'expense_document',
                    'id' => $expense->id,
                    'sort_id' => $expense->id,
                    'number' => $expense->number,
                    'expense_date' => $expense->expense_date?->toDateString(),
                    'received_date' => $expense->received_date?->toDateString(),
                    'amount' => (string) $expense->amount,
                    'currency' => $expense->currency,
                    'details' => $expense->description,
                    'reference' => $expense->reference,
                    'expense_type_id' => $type?->id,
                    'expense_type_code' => $type?->code,
                    'expense_type_name' => $type?->name,
                    'expense_category_id' => $category?->id,
                    'expense_category_code' => $category?->code,
                    'expense_category_name' => $category?->name,
                    'expense_item_id' => $item?->id,
                    'expense_code' => $item?->code ?? $expense->chartAccount?->code,
                    'expense_name' => $item?->name ?? $expense->chartAccount?->name,
                    'chart_account_id' => $expense->chart_account_id,
                    'chart_account' => $expense->chartAccount,
                    'branch_id' => $expense->branch_id,
                    'branch' => $expense->branch,
                    'payee' => $expense->payee,
                    'approval_status' => $expense->approval_status,
                    'approved_at' => $expense->approved_at?->toISOString(),
                    'created_by' => $expense->creator,
                    'approved_by' => $expense->approver,
                    'payment_status' => $expense->status,
                    'is_received' => (bool) $expense->is_received,
                    'source_type' => $sourceType,
                    'source_id' => $expense->source_id ?? $expense->legacy_id ?? $expense->id,
                    'system_generated' => (bool) $expense->system_generated,
                    'read_only' => (bool) $expense->system_generated,
                ];
            });
    }

    private function journalExpenses(
        array $filters,
        Collection $itemsByChart,
        Collection $representedSources,
    ): Collection {
        $query = JournalLine::query()
            ->where('debit', '>', 0)
            ->whereHas('chartAccount', fn ($account) => $account->where('type', 'expense'))
            ->whereDoesntHave('entry.payment', fn ($payment) => $payment->whereNotNull('expense_id'))
            ->with([
                'chartAccount', 'branch',
                'entry.loanPayment.loan', 'entry.loanPayment.paymentMethod',
                'entry.payment.lines.paymentMethod',
                'entry.financialAccountAdjustment.paymentObligation',
                'entry.financialAccountAdjustment.adjustmentAccount',
                'entry.financialAccountTransfer', 'entry.customerRefund',
            ])
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereHas(
                'entry', fn ($entry) => $entry->whereDate('entry_date', '>=', $date)
            ))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereHas(
                'entry', fn ($entry) => $entry->whereDate('entry_date', '<=', $date)
            ))
            ->when($filters['branch_id'] ?? null, fn ($query, $id) => $query->where('branch_id', $id))
            ->when($filters['chart_account_id'] ?? null, fn ($query, $id) => $query->where('chart_account_id', $id))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($scope) use ($search) {
                $scope->where('description', 'like', "%{$search}%")
                    ->orWhereHas('chartAccount', fn ($account) => $account
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"))
                    ->orWhereHas('entry', fn ($entry) => $entry
                        ->where('number', 'like', "%{$search}%")
                        ->orWhere('memo', 'like', "%{$search}%"));
            }));

        return $query->get()
            ->map(function (JournalLine $line) use ($filters, $itemsByChart) {
                [$sourceType, $sourceId, $reference] = $this->journalSource($line);
                $item = $this->matchingItem($itemsByChart->get($line->chart_account_id, collect()), $sourceType, $line, $filters);
                $category = $item?->category;
                $type = $category?->expenseType;

                return [
                    'register_id' => 'journal-line:'.$line->id,
                    'record_type' => 'journal_expense',
                    'id' => $line->id,
                    'sort_id' => $line->id,
                    'number' => $line->entry->number,
                    'expense_date' => $line->entry->entry_date->toDateString(),
                    'received_date' => $line->entry->entry_date->toDateString(),
                    'amount' => (string) $line->debit,
                    'currency' => $line->entry->loanPayment?->loan?->currency
                        ?? $line->entry->payment?->currency
                        ?? $line->entry->financialAccountAdjustment?->currency
                        ?? 'PHP',
                    'details' => $line->description ?: $line->entry->memo,
                    'reference' => $reference,
                    'expense_type_id' => $type?->id,
                    'expense_type_code' => $type?->code,
                    'expense_type_name' => $type?->name,
                    'expense_category_id' => $category?->id,
                    'expense_category_code' => $category?->code,
                    'expense_category_name' => $category?->name,
                    'expense_item_id' => $item?->id,
                    'expense_code' => $item?->code ?? $line->chartAccount?->code,
                    'expense_name' => $item?->name ?? $line->chartAccount?->name,
                    'chart_account_id' => $line->chart_account_id,
                    'chart_account' => $line->chartAccount,
                    'branch_id' => $line->branch_id,
                    'branch' => $line->branch,
                    'payee' => null,
                    'approval_status' => 'approved',
                    'approved_at' => $line->created_at?->toISOString(),
                    'created_by' => null,
                    'approved_by' => null,
                    'payment_status' => 'posted',
                    'is_received' => true,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'system_generated' => true,
                    'read_only' => true,
                    'journal_entry_id' => $line->journal_entry_id,
                ];
            })
            ->reject(function (array $row) use ($representedSources) {
                return $representedSources->has($row['source_type'].':'.$row['source_id'])
                    || $representedSources->has('journal_entry:'.$row['journal_entry_id'])
                    || $representedSources->has('journal_line:'.$row['id']);
            });
    }

    private function matchingItem(Collection $candidates, string $sourceType, JournalLine $line, array $filters)
    {
        $filtered = $candidates
            ->when($filters['expense_type_id'] ?? null, fn ($items, $id) => $items->filter(
                fn ($item) => (int) $item->category?->expense_type_id === (int) $id
            ))
            ->when($filters['expense_category_id'] ?? null, fn ($items, $id) => $items->where('expense_category_id', (int) $id))
            ->when($filters['expense_item_id'] ?? null, fn ($items, $id) => $items->where('id', (int) $id));
        if ($filtered->isEmpty()) {
            return null;
        }

        $preferredKey = match ($sourceType) {
            'loan_interest' => str_contains(strtolower((string) $line->description), 'overdue')
                ? 'LOAN_OVERDUE_INTEREST'
                : 'LOAN_INTEREST',
            'credit_card_adjustment' => 'CREDIT_CARD_INTEREST',
            'bank_adjustment' => 'BANK_SERVICE_CHARGE',
            default => null,
        };

        return ($preferredKey ? $filtered->firstWhere('system_key', $preferredKey) : null) ?? $filtered->first();
    }

    private function journalSource(JournalLine $line): array
    {
        $entry = $line->entry;
        if ($payment = $entry->loanPayment) {
            return ['loan_interest', $payment->id, $payment->reference];
        }
        if ($adjustment = $entry->financialAccountAdjustment) {
            return [
                $adjustment->payment_obligation_id ? 'credit_card_adjustment' : 'bank_adjustment',
                $adjustment->id,
                $adjustment->reference,
            ];
        }
        if ($payment = $entry->payment) {
            return ['direct_expense', $payment->id, $payment->lines->first()?->reference];
        }
        if ($transfer = $entry->financialAccountTransfer) {
            return ['account_transfer_expense', $transfer->id, $transfer->reference];
        }
        if ($refund = $entry->customerRefund) {
            return ['customer_refund_expense', $refund->id, $refund->reference];
        }

        return ['journal_expense', $entry->id, null];
    }

    private function matchesNormalizedFilters(array $row, array $filters): bool
    {
        if (($filters['source_type'] ?? null) && $row['source_type'] !== $filters['source_type']) {
            return false;
        }
        if (array_key_exists('system_generated', $filters)
            && $row['system_generated'] !== (bool) $filters['system_generated']) {
            return false;
        }
        if (($filters['approval_status'] ?? null) && $row['approval_status'] !== $filters['approval_status']) {
            return false;
        }
        if (($filters['payment_status'] ?? null) && $row['payment_status'] !== $filters['payment_status']) {
            return false;
        }
        if (($filters['expense_type_id'] ?? null) && (int) $row['expense_type_id'] !== (int) $filters['expense_type_id']) {
            return false;
        }
        if (($filters['expense_category_id'] ?? null) && (int) $row['expense_category_id'] !== (int) $filters['expense_category_id']) {
            return false;
        }
        if (($filters['expense_item_id'] ?? null) && (int) $row['expense_item_id'] !== (int) $filters['expense_item_id']) {
            return false;
        }

        return true;
    }
}
