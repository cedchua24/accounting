<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialAccountTransferRequest;
use App\Models\FinancialAccountTransfer;
use App\Services\FinancialAccountTransferService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialAccountTransferController extends Controller
{
    public function __construct(private FinancialAccountTransferService $transfers) {}

    public function index(Request $request)
    {
        $request->merge([
            'date_from' => $request->input('date_from', $request->input('from')),
            'date_to' => $request->input('date_to', $request->input('to')),
            'financial_account_id' => $request->input('financial_account_id', $request->input('bank_id')),
            'status' => $request->input('status') === 'void' ? 'voided' : $request->input('status'),
        ]);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::in(['draft', 'posted', 'voided'])],
            'financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'source_financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'destination_financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'destination_type' => ['nullable', Rule::in(['internal', 'external'])],
            'transfer_method' => ['nullable', Rule::in(['cash', 'online', 'e_wallet', 'cheque'])],
            'counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return FinancialAccountTransfer::with(
            'sourceAccount.institution', 'destinationAccount.institution', 'counterparty', 'offsetAccount'
        )
            ->when($filters['financial_account_id'] ?? null, fn ($query, $id) => $query->where(
                fn ($accounts) => $accounts
                    ->where('source_financial_account_id', $id)
                    ->orWhere('destination_financial_account_id', $id)
            ))
            ->when($filters['source_financial_account_id'] ?? null, fn ($query, $id) => $query->where('source_financial_account_id', $id))
            ->when($filters['destination_financial_account_id'] ?? null, fn ($query, $id) => $query->where('destination_financial_account_id', $id))
            ->when($filters['destination_type'] ?? null, fn ($query, $type) => $query->where('destination_type', $type))
            ->when($filters['transfer_method'] ?? null, fn ($query, $method) => $query->where('transfer_method', $method))
            ->when($filters['counterparty_id'] ?? null, fn ($query, $id) => $query->where('counterparty_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('transfer_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('transfer_date', '<=', $date))
            ->latest('transfer_date')->latest('id')->paginate($filters['per_page'] ?? 15);
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
            'counterparty', 'offsetAccount',
            'journalEntries.lines.chartAccount',
        ];
    }
}
