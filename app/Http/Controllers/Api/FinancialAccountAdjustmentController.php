<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialAccountAdjustmentRequest;
use App\Models\FinancialAccountAdjustment;
use App\Services\FinancialAccountAdjustmentService;
use Illuminate\Http\Request;

class FinancialAccountAdjustmentController extends Controller
{
    public function __construct(private FinancialAccountAdjustmentService $adjustments) {}

    public function index(Request $request)
    {
        return FinancialAccountAdjustment::with('financialAccount.institution', 'adjustmentAccount', 'paymentObligation')
            ->when($request->financial_account_id, fn ($query, $id) => $query->where('financial_account_id', $id))
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->when($request->from, fn ($query, $date) => $query->whereDate('adjustment_date', '>=', $date))
            ->when($request->to, fn ($query, $date) => $query->whereDate('adjustment_date', '<=', $date))
            ->latest('adjustment_date')->latest('id')->paginate();
    }

    public function store(StoreFinancialAccountAdjustmentRequest $request)
    {
        $adjustment = $this->adjustments->create($request->validated(), $request->user()->id);

        return response()->json($adjustment, 201);
    }

    public function show(FinancialAccountAdjustment $financialAccountAdjustment)
    {
        return $financialAccountAdjustment->load($this->relations());
    }

    public function post(Request $request, FinancialAccountAdjustment $financialAccountAdjustment)
    {
        return $this->adjustments->post($financialAccountAdjustment, $request->user()->id);
    }

    public function void(Request $request, FinancialAccountAdjustment $financialAccountAdjustment)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->adjustments->void($financialAccountAdjustment, $data['reason'], $request->user()->id);
    }

    public function destroy(FinancialAccountAdjustment $financialAccountAdjustment)
    {
        abort_unless($financialAccountAdjustment->status === 'draft', 409, 'Only draft balance adjustments can be deleted.');
        $financialAccountAdjustment->delete();

        return response()->noContent();
    }

    private function relations(): array
    {
        return [
            'financialAccount.institution', 'financialAccount.chartAccount',
            'adjustmentAccount', 'paymentObligation', 'journalEntries.lines.chartAccount',
        ];
    }
}
