<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIntegratedExpenseRequest;
use App\Services\IntegratedExpenseService;
use Illuminate\Http\Request;

class IntegratedExpenseController extends Controller
{
    public function __construct(private IntegratedExpenseService $expenses) {}

    public function store(StoreIntegratedExpenseRequest $request)
    {
        return response()->json(
            $this->expenses->record($request->validated(), $request->user()->id),
            201
        );
    }

    public function summaries(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'external_expense_ids' => ['required', 'array', 'max:500'],
            'external_expense_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return response()->json([
            'data' => $this->expenses->findMany($data['source'], $data['external_expense_ids']),
        ]);
    }

    public function cancel(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'external_expense_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->expenses->cancel(
            $data['source'],
            $data['external_expense_id'],
            $data['reason'],
            $request->user()->id,
        ));
    }
}
