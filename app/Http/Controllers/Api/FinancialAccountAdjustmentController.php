<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetAccountAdjustmentRequest;
use App\Http\Requests\StoreFinancialAccountAdjustmentRequest;
use App\Models\FinancialAccount;
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

    public function accountIndex(Request $request, FinancialAccount $financialAccount)
    {
        $this->assertAdjustableAssetAccount($financialAccount);
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,posted,voided'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $adjustments = FinancialAccountAdjustment::query()
            ->with($this->relations())
            ->where('financial_account_id', $financialAccount->id)
            ->whereNull('payment_obligation_id')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('adjustment_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('adjustment_date', '<=', $date))
            ->latest('adjustment_date')
            ->latest('id')
            ->paginate()
            ->through(fn (FinancialAccountAdjustment $adjustment) => $this->accountAdjustmentDetails($adjustment));

        return response()->json($adjustments);
    }

    public function accountStore(StoreAssetAccountAdjustmentRequest $request, FinancialAccount $financialAccount)
    {
        $this->assertAdjustableAssetAccount($financialAccount);
        $data = $request->validated();
        $adjustment = $this->adjustments->create([
            'financial_account_id' => $financialAccount->id,
            'adjustment_account_id' => $data['adjustment_account_id'],
            'adjustment_date' => $data['adjustment_date'],
            'currency' => $financialAccount->currency,
            'actual_balance' => $data['actual_balance'],
            'reference' => $data['reference'] ?? null,
            'reason' => $data['reason'],
            'post' => true,
        ], $request->user()->id);

        return response()->json($this->accountAdjustmentDetails($adjustment), 201);
    }

    public function accountShow(FinancialAccount $financialAccount, FinancialAccountAdjustment $financialAccountAdjustment)
    {
        $this->assertAssetAdjustment($financialAccount, $financialAccountAdjustment);

        return response()->json($this->accountAdjustmentDetails(
            $financialAccountAdjustment->load($this->relations())
        ));
    }

    public function accountVoid(Request $request, FinancialAccount $financialAccount, FinancialAccountAdjustment $financialAccountAdjustment)
    {
        $this->assertAssetAdjustment($financialAccount, $financialAccountAdjustment);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $adjustment = $this->adjustments->void($financialAccountAdjustment, $data['reason'], $request->user()->id);

        return response()->json($this->accountAdjustmentDetails($adjustment));
    }

    private function assertAdjustableAssetAccount(FinancialAccount $financialAccount): void
    {
        abort_unless(
            in_array($financialAccount->kind, ['cash', 'bank_account', 'checking_account', 'e_wallet'], true),
            404,
            'The selected financial account does not hold an adjustable cash balance.'
        );
    }

    private function assertAssetAdjustment(FinancialAccount $financialAccount, FinancialAccountAdjustment $adjustment): void
    {
        $this->assertAdjustableAssetAccount($financialAccount);
        abort_unless(
            (int) $adjustment->financial_account_id === (int) $financialAccount->id
            && $adjustment->payment_obligation_id === null,
            404,
            'The selected financial-account adjustment was not found.'
        );
    }

    private function accountAdjustmentDetails(FinancialAccountAdjustment $adjustment): array
    {
        $difference = (string) $adjustment->adjustment_amount;

        return [
            'id' => $adjustment->id,
            'number' => $adjustment->number,
            'status' => $adjustment->status,
            'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
            'currency' => $adjustment->currency,
            'system_balance_before' => $adjustment->system_balance_before,
            'actual_balance' => $adjustment->actual_balance,
            'adjustment_amount' => $difference,
            'direction' => bccomp($difference, '0.0000', 4) === 1
                ? 'INCREASE'
                : (bccomp($difference, '0.0000', 4) === -1 ? 'DECREASE' : 'NO_CHANGE'),
            'reference' => $adjustment->reference,
            'reason' => $adjustment->reason,
            'financial_account' => $adjustment->financialAccount,
            'adjustment_account' => $adjustment->adjustmentAccount,
            'journal_transactions' => $adjustment->journalEntries,
            'audit' => [
                'created_by' => $adjustment->createdBy,
                'created_at' => $adjustment->created_at,
                'posted_by' => $adjustment->postedBy,
                'posted_at' => $adjustment->posted_at,
                'voided_by' => $adjustment->voidedBy,
                'voided_at' => $adjustment->voided_at,
                'void_reason' => $adjustment->void_reason,
            ],
        ];
    }

    private function relations(): array
    {
        return [
            'financialAccount.institution', 'financialAccount.chartAccount',
            'adjustmentAccount', 'paymentObligation', 'journalEntries.lines.chartAccount',
            'createdBy', 'postedBy', 'voidedBy',
        ];
    }
}
