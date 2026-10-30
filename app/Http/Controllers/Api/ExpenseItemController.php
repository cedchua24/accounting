<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseItemRequest;
use App\Models\ExpenseItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ExpenseItemController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $items = $this->query($filters)->paginate($filters['per_page'] ?? 25);
        $items->through(fn (ExpenseItem $item) => $this->data($item));

        return $items;
    }

    public function list(Request $request)
    {
        $filters = $this->filters($request, false);

        return response()->json($this->query($filters)->get()->map(fn (ExpenseItem $item) => $this->data($item)));
    }

    public function store(StoreExpenseItemRequest $request)
    {
        $item = ExpenseItem::create($request->validated());

        return response()->json($this->data($item->load('category.type', 'chartAccount')->loadCount('expenses')), 201);
    }

    public function show(ExpenseItem $expenseItem)
    {
        return $this->data($expenseItem->load('category.type', 'chartAccount')->loadCount('expenses'));
    }

    public function update(StoreExpenseItemRequest $request, ExpenseItem $expenseItem)
    {
        $expenseItem->update($request->validated());

        return $this->data($expenseItem->fresh(['category.type', 'chartAccount'])->loadCount('expenses'));
    }

    public function destroy(ExpenseItem $expenseItem)
    {
        if ($expenseItem->system_key || $expenseItem->expenses()->exists()) {
            return response()->json([
                'message' => 'This expense item is a system item or is already in use. Deactivate or hide it instead of deleting it.',
            ], 409);
        }
        $expenseItem->delete();

        return response()->noContent();
    }

    private function filters(Request $request, bool $paginated = true): array
    {
        return $request->validate([
            'expense_type_id' => ['nullable', 'integer', 'exists:expense_types,id'],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'chart_account_id' => ['nullable', 'integer', 'exists:chart_accounts,id'],
            'active' => ['nullable', 'boolean'],
            'hidden' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => $paginated ? ['nullable', 'integer', 'min:1', 'max:100'] : ['prohibited'],
        ]);
    }

    private function query(array $filters): Builder
    {
        return ExpenseItem::query()
            ->with('category.type', 'chartAccount')->withCount('expenses')
            ->when($filters['expense_type_id'] ?? null, fn ($query, $id) => $query->whereHas(
                'category', fn ($category) => $category->where('expense_type_id', $id)
            ))
            ->when($filters['expense_category_id'] ?? null, fn ($query, $id) => $query->where('expense_category_id', $id))
            ->when($filters['chart_account_id'] ?? null, fn ($query, $id) => $query->where('chart_account_id', $id))
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $filters['active']))
            ->when(array_key_exists('hidden', $filters), fn ($query) => $query->where('is_hidden', $filters['hidden']))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($match) => $match->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('details', 'like', "%{$search}%")
            ))
            ->orderBy('expense_category_id')->orderBy('display_order')->orderBy('code');
    }

    private function data(ExpenseItem $item): array
    {
        return [
            ...$item->toArray(),
            'expense_code' => $item->code,
            'expense_name' => $item->name,
            'status' => $item->is_active ? 0 : 1,
        ];
    }
}
