<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialAccountTransferRequest;
use App\Models\FinancialAccountTransfer;
use App\Services\FinancialAccountTransferService;
use Illuminate\Http\Request;

class FinancialAccountTransferController extends Controller
{
    public function __construct(private FinancialAccountTransferService $transfers) {}

    public function index(Request $request)
    {
        return FinancialAccountTransfer::with('sourceAccount.institution', 'destinationAccount.institution')
            ->when($request->source_financial_account_id, fn ($query, $id) => $query->where('source_financial_account_id', $id))
            ->when($request->destination_financial_account_id, fn ($query, $id) => $query->where('destination_financial_account_id', $id))
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->when($request->from, fn ($query, $date) => $query->whereDate('transfer_date', '>=', $date))
            ->when($request->to, fn ($query, $date) => $query->whereDate('transfer_date', '<=', $date))
            ->latest('transfer_date')->latest('id')->paginate();
    }

    public function store(StoreFinancialAccountTransferRequest $request)
    {
        $transfer = $this->transfers->create($request->validated(), $request->user()->id);

        return response()->json($transfer, 201);
    }

    public function show(FinancialAccountTransfer $financialAccountTransfer)
    {
        return $financialAccountTransfer->load($this->relations());
    }

    public function post(Request $request, FinancialAccountTransfer $financialAccountTransfer)
    {
        return $this->transfers->post($financialAccountTransfer, $request->user()->id);
    }

    public function void(Request $request, FinancialAccountTransfer $financialAccountTransfer)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->transfers->void($financialAccountTransfer, $data['reason'], $request->user()->id);
    }

    public function destroy(FinancialAccountTransfer $financialAccountTransfer)
    {
        abort_unless($financialAccountTransfer->status === 'draft', 409, 'Only draft account transfers can be deleted.');
        $financialAccountTransfer->delete();

        return response()->noContent();
    }

    private function relations(): array
    {
        return [
            'sourceAccount.institution', 'sourceAccount.chartAccount',
            'destinationAccount.institution', 'destinationAccount.chartAccount',
            'journalEntries.lines.chartAccount',
        ];
    }
}
