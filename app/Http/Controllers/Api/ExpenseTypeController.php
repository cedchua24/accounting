<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseTypeRequest;
use App\Models\ExpenseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ExpenseTypeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $types = $this->query($filters)->paginate($filters['per_page'] ?? 25);
        $types->through(fn (ExpenseType $type) => $this->data($type));

        return $types;
    }

    public function list(Request $request)
    {
        $filters = $this->filters($request, false);

        return response()->json($this->query($filters)->get()->map(fn (ExpenseType $type) => $this->data($type)));
    }

    public function store(StoreExpenseTypeRequest $request)
    {
        $type = ExpenseType::create($request->validated());

        return response()->json($this->data($type->load('chartAccount', 'chartAccountGroup')->loadCount('categories')), 201);
    }

    public function show(ExpenseType $expenseType)
    {
        return $this->data($expenseType->load('chartAccount', 'chartAccountGroup')->loadCount('categories'));
    }

    public function update(StoreExpenseTypeRequest $request, ExpenseType $expenseType)
    {
        $expenseType->update($request->validated());

        return $this->data($expenseType->fresh(['chartAccount', 'chartAccountGroup'])->loadCount('categories'));
    }

    public function destroy(ExpenseType $expenseType)
    {
        if ($expenseType->categories()->exists()) {
            return response()->json([
                'message' => 'This expense type already has categories. Deactivate it instead of deleting it.',
            ], 409);
        }
        $expenseType->delete();

        return response()->noContent();
    }

    private function filters(Request $request, bool $paginated = true): array
    {
        return $request->validate([
            'active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'chart_account_group_id' => ['nullable', 'integer', 'exists:chart_account_groups,id'],
            'chart_account_id' => ['nullable', 'integer', 'exists:chart_accounts,id'],
            'per_page' => $paginated ? ['nullable', 'integer', 'min:1', 'max:100'] : ['prohibited'],
        ]);
    }

    private function query(array $filters): Builder
    {
        return ExpenseType::query()
            ->with('chartAccount', 'chartAccountGroup')->withCount('categories')
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $filters['active']))
            ->when($filters['chart_account_group_id'] ?? null, fn ($query, $id) => $query->where('chart_account_group_id', $id))
            ->when($filters['chart_account_id'] ?? null, fn ($query, $id) => $query->where('chart_account_id', $id))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($match) => $match->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")
            ))
            ->orderBy('display_order')->orderBy('code');
    }

    private function data(ExpenseType $type): array
    {
        return [
            ...$type->toArray(),
            'expense_type_code' => $type->code,
            'expense_type' => $type->name,
            'status' => $type->is_active ? 0 : 1,
        ];
    }
}
