<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEndOfDaySettlementRequest;
use App\Models\EndOfDaySettlement;
use App\Services\EndOfDaySettlementService;
use Illuminate\Http\Request;

class EndOfDaySettlementController extends Controller
{
    public function __construct(private EndOfDaySettlementService $settlements) {}

    public function index(Request $request)
    {
        return EndOfDaySettlement::with('branches', 'destinationAccount.institution')
            ->when($request->branch_id, fn ($query, $branchId) => $query->whereHas('branches', fn ($branches) => $branches->whereKey($branchId)))
            ->when($request->business_date, fn ($query, $date) => $query->whereDate('business_date', $date))
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->latest('business_date')->latest('id')->paginate();
    }

    public function store(StoreEndOfDaySettlementRequest $request)
    {
        $settlement = $this->settlements->create($request->validated(), $request->user()->id);

        return response()->json($settlement, 201);
    }

    public function show(EndOfDaySettlement $endOfDaySettlement)
    {
        return $endOfDaySettlement->load($this->relations());
    }

    public function post(Request $request, EndOfDaySettlement $endOfDaySettlement)
    {
        return $this->settlements->post($endOfDaySettlement, $request->user()->id);
    }

    public function void(Request $request, EndOfDaySettlement $endOfDaySettlement)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->settlements->void($endOfDaySettlement, $data['reason'], $request->user()->id);
    }

    public function destroy(EndOfDaySettlement $endOfDaySettlement)
    {
        abort_unless($endOfDaySettlement->status === 'draft', 409, 'Only draft end-of-day settlements can be deleted.');
        $endOfDaySettlement->delete();

        return response()->noContent();
    }

    private function relations(): array
    {
        return [
            'branches', 'destinationAccount.institution', 'destinationAccount.chartAccount',
            'items.branch', 'items.sourceAccount.institution', 'items.paymentLine.payment',
            'journalEntries.lines.chartAccount',
        ];
    }
}
