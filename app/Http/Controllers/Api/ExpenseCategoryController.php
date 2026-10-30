<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $categories = $this->query($filters)->paginate($filters['per_page'] ?? 25);
        $categories->through(fn (ExpenseCategory $category) => $this->data($category));

        return $categories;
    }

    public function list(Request $request)
    {
        $filters = $this->filters($request, false);

        return response()->json($this->query($filters)->get()
            ->map(fn (ExpenseCategory $category) => $this->data($category)));
    }

    public function store(StoreExpenseCategoryRequest $request)
    {
        $category = ExpenseCategory::create($request->validated());

        return response()->json($this->data($category->load('type', 'chartAccount')->loadCount('items')), 201);
    }

    public function show(ExpenseCategory $expenseCategory)
    {
        return $this->data($expenseCategory->load('type', 'chartAccount')->loadCount('items'));
    }

    public function update(StoreExpenseCategoryRequest $request, ExpenseCategory $expenseCategory)
    {
        $expenseCategory->update($request->validated());

        return $this->data($expenseCategory->fresh(['type', 'chartAccount'])->loadCount('items'));
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        if ($expenseCategory->items()->exists()) {
            return response()->json([
                'message' => 'This expense category already has expense items. Deactivate it instead of deleting it.',
            ], 409);
        }
        $expenseCategory->delete();

        return response()->noContent();
    }

    private function filters(Request $request, bool $paginated = true): array
    {
        return $request->validate([
            'expense_type_id' => ['nullable', 'integer', 'exists:expense_types,id'],
            'active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => $paginated ? ['nullable', 'integer', 'min:1', 'max:100'] : ['prohibited'],
        ]);
    }

    private function query(array $filters): Builder
    {
        return ExpenseCategory::query()
            ->with('type', 'chartAccount')->withCount('items')
            ->when($filters['expense_type_id'] ?? null, fn ($query, $id) => $query->where('expense_type_id', $id))
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $filters['active']))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($match) => $match->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")
            ))
            ->orderBy('expense_type_id')->orderBy('display_order')->orderBy('code');
    }

    private function data(ExpenseCategory $category): array
    {
        return [
            ...$category->toArray(),
            'expense_category_code' => $category->code,
            'expense_category_name' => $category->name,
            'status' => $category->is_active ? 0 : 1,
        ];
    }
}
