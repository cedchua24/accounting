<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        return Expense::with('branch', 'payee', 'chartAccount')
            ->when($request->branch_id, fn ($query, $branch) => $query->where('branch_id', $branch))
            ->latest('expense_date')->paginate();
    }

    public function store(StoreExpenseRequest $request)
    {
        return response()->json(Expense::create($request->validated())->load('branch', 'payee', 'chartAccount'), 201);
    }

    public function show(Expense $expense)
    {
        return $expense->load('branch', 'payee', 'chartAccount', 'payments');
    }

    public function update(StoreExpenseRequest $request, Expense $expense)
    {
        $expense->update($request->validated());

        return $expense->fresh(['branch', 'payee', 'chartAccount']);
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();

        return response()->noContent();
    }
}
