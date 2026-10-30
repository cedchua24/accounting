<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'expense_type_id' => ['nullable', 'integer', 'exists:expense_types,id'],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'expense_item_id' => ['nullable', 'integer', 'exists:expense_items,id'],
            'chart_account_id' => ['nullable', 'integer', 'exists:chart_accounts,id'],
            'status' => ['nullable', 'in:unpaid,partially_paid,paid,cancelled'],
            'approval_status' => ['nullable', 'in:draft,pending,approved,rejected'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $expenses = Expense::query()
            ->with($this->relations())
            ->withSum(['payments as paid_amount' => fn ($query) => $query->where('status', 'posted')], 'amount')
            ->when($filters['branch_id'] ?? null, fn ($query, $value) => $query->where('branch_id', $value))
            ->when($filters['expense_item_id'] ?? null, fn ($query, $value) => $query->where('expense_item_id', $value))
            ->when($filters['expense_category_id'] ?? null, fn ($query, $value) => $query->whereHas(
                'expenseItem', fn ($item) => $item->where('expense_category_id', $value)
            ))
            ->when($filters['expense_type_id'] ?? null, fn ($query, $value) => $query->whereHas(
                'expenseItem.category', fn ($category) => $category->where('expense_type_id', $value)
            ))
            ->when($filters['chart_account_id'] ?? null, fn ($query, $value) => $query->where('chart_account_id', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['approval_status'] ?? null, fn ($query, $value) => $query->where('approval_status', $value))
            ->when($filters['date_from'] ?? null, fn ($query, $value) => $query->whereDate('expense_date', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($query, $value) => $query->whereDate('expense_date', '<=', $value))
            ->when($filters['search'] ?? null, fn ($query, $value) => $query->where(function ($search) use ($value) {
                $search->where('number', 'like', "%{$value}%")
                    ->orWhere('description', 'like', "%{$value}%")
                    ->orWhere('reference', 'like', "%{$value}%")
                    ->orWhereHas('expenseItem', fn ($item) => $item->where('name', 'like', "%{$value}%"))
                    ->orWhereHas('payee', fn ($payee) => $payee->where('name', 'like', "%{$value}%"));
            }))
            ->latest('expense_date')->latest('id')
            ->paginate($filters['per_page'] ?? 15);
        $expenses->through(fn (Expense $expense) => $this->present($expense));

        return $expenses;
    }

    public function store(StoreExpenseRequest $request)
    {
        $expense = DB::transaction(function () use ($request) {
            $data = $this->resolveItem($request->validated());
            $data['created_by'] = $request->user()?->id;
            $data['approval_status'] = $data['approval_status'] ?? 'approved';
            if ($data['approval_status'] === 'approved') {
                $data['approved_by'] = $request->user()?->id;
                $data['approved_at'] = now();
            }

            return Expense::create($data);
        });

        return response()->json($this->present($expense->load($this->relations())), 201);
    }

    public function show(Expense $expense)
    {
        $expense->load([...$this->relations(), 'payments.lines.financialAccount.institution', 'payments.lines.paymentMethod']);
        $expense->setAttribute('paid_amount', $expense->payments->where('status', 'posted')->sum('amount'));

        return $this->present($expense);
    }

    public function update(StoreExpenseRequest $request, Expense $expense)
    {
        abort_if($expense->system_generated, 409, 'System-generated expenses must be changed through their source transaction.');
        abort_if($expense->payments()->where('status', 'posted')->exists(), 409, 'A paid expense cannot be edited. Void its payment first.');
        $expense->update($this->resolveItem($request->validated()));

        return $this->present($expense->fresh($this->relations()));
    }

    public function destroy(Expense $expense)
    {
        abort_if($expense->system_generated, 409, 'System-generated expenses must be reversed through their source transaction.');
        abort_if($expense->payments()->exists(), 409, 'An expense with payment history cannot be deleted. Cancel or void it instead.');
        $expense->delete();

        return response()->noContent();
    }

    public function approve(Request $request, Expense $expense)
    {
        abort_if($expense->system_generated, 409, 'System-generated expenses are already approved by their source workflow.');
        abort_if($expense->approval_status === 'rejected', 409, 'A rejected expense must be edited before approval.');
        $data = $request->validate(['notes' => ['nullable', 'string']]);
        $expense->update([
            'approval_status' => 'approved',
            'approval_notes' => $data['notes'] ?? $expense->approval_notes,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return $this->present($expense->fresh($this->relations()));
    }

    public function reject(Request $request, Expense $expense)
    {
        abort_if($expense->system_generated, 409, 'System-generated expenses must be reversed through their source workflow.');
        abort_if($expense->payments()->where('status', 'posted')->exists(), 409, 'A paid expense cannot be rejected.');
        $data = $request->validate(['notes' => ['required', 'string']]);
        $expense->update([
            'approval_status' => 'rejected',
            'approval_notes' => $data['notes'],
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return $this->present($expense->fresh($this->relations()));
    }

    private function resolveItem(array $data): array
    {
        if (! empty($data['expense_item_id'])) {
            $item = ExpenseItem::query()->where('is_active', true)->where('is_hidden', false)
                ->findOrFail($data['expense_item_id']);
            $data['chart_account_id'] = $item->chart_account_id;
        }

        return $data;
    }

    private function relations(): array
    {
        return [
            'branch', 'payee', 'chartAccount.group',
            'expenseItem.category.expenseType', 'creator', 'approver',
        ];
    }

    private function present(Expense $expense): array
    {
        $paid = bcadd((string) ($expense->paid_amount ?? 0), '0', 4);
        $balance = bcsub((string) $expense->amount, $paid, 4);

        return [
            ...$expense->toArray(),
            'expense_code' => $expense->expenseItem?->code,
            'expense_name' => $expense->expenseItem?->name ?? $expense->chartAccount?->name,
            'expense_category' => $expense->expenseItem?->category,
            'expense_type' => $expense->expenseItem?->category?->expenseType,
            'paid_amount' => $paid,
            'balance_due' => bccomp($balance, '0.0000', 4) === 1 ? $balance : '0.0000',
            'read_only' => (bool) $expense->system_generated,
        ];
    }
}
