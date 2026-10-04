<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialAccountController extends Controller
{
    public function index(Request $request)
    {
        return FinancialAccount::with('ownerBranch', 'accessibleBranches', 'institution', 'chartAccount', 'applications')
            ->when($request->branch_id, fn ($query, $branch) => $query->availableToBranch((int) $branch))
            ->when($request->kind, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($request->legacy_source, fn ($query, $source) => $query->where('legacy_source', $source))
            ->when($request->legacy_id, fn ($query, $legacyId) => $query->where('legacy_id', $legacyId))
            ->when($request->application, fn ($query, $application) => $query->whereHas('applications', fn ($usage) => $usage->where('application', $application)))
            ->when($request->boolean('receipts'), fn ($query) => $query->where('usable_for_receipts', true))
            ->when($request->boolean('payments'), fn ($query) => $query->where('usable_for_payments', true))
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFinancialAccountRequest $request)
    {
        $account = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $applications = $data['applications'] ?? null;
            $branchIds = $data['branch_ids'] ?? [];
            unset($data['applications'], $data['branch_ids']);
            $account = FinancialAccount::create($data);
            $this->syncApplications($account, $applications);
            $account->accessibleBranches()->sync($account->is_company_wide ? [] : $branchIds);

            return $account;
        });

        return response()->json($account->load('ownerBranch', 'accessibleBranches', 'institution', 'chartAccount', 'applications'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(FinancialAccount $financialAccount)
    {
        return $financialAccount->load('ownerBranch', 'accessibleBranches', 'institution', 'chartAccount', 'applications');
    }

    public function balance(Request $request, FinancialAccount $financialAccount)
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'as_of' => ['nullable', 'date'],
        ]);
        $financialAccount->load('chartAccount');

        $baseQuery = JournalLine::query()
            ->where('chart_account_id', $financialAccount->chart_account_id)
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['as_of'] ?? null, fn ($query, $date) => $query->whereHas('entry', fn ($entry) => $entry->whereDate('entry_date', '<=', $date)));

        $debits = (string) (clone $baseQuery)->sum('debit');
        $credits = (string) (clone $baseQuery)->sum('credit');
        $normalSide = in_array($financialAccount->chartAccount->type, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit';
        $balance = $normalSide === 'debit'
            ? bcsub($debits, $credits, 4)
            : bcsub($credits, $debits, 4);

        return response()->json([
            'financial_account_id' => $financialAccount->id,
            'name' => $financialAccount->name,
            'currency' => $financialAccount->currency,
            'branch_id' => isset($filters['branch_id']) ? (int) $filters['branch_id'] : null,
            'as_of' => $filters['as_of'] ?? null,
            'normal_side' => $normalSide,
            'debits' => number_format((float) $debits, 4, '.', ''),
            'credits' => number_format((float) $credits, 4, '.', ''),
            'balance' => $balance,
        ]);
    }

    public function movements(Request $request, FinancialAccount $financialAccount)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);
        $financialAccount->load('chartAccount');

        $movements = JournalLine::query()
            ->where('chart_account_id', $financialAccount->chart_account_id)
            ->with([
                'branch', 'entry.payment.allocations.obligation',
                'entry.financialAccountTransfer', 'entry.financialAccountAdjustment.paymentObligation',
            ])
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereHas('entry', fn ($entry) => $entry->whereDate('entry_date', '>=', $date)))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereHas('entry', fn ($entry) => $entry->whereDate('entry_date', '<=', $date)))
            ->latest('id')->paginate();

        $movements->through(function (JournalLine $line) use ($financialAccount) {
            $normalCredit = in_array($financialAccount->chartAccount->type, ['liability', 'equity', 'revenue'], true);
            $effect = $normalCredit
                ? bcsub((string) $line->credit, (string) $line->debit, 4)
                : bcsub((string) $line->debit, (string) $line->credit, 4);
            $movementType = $this->movementType($line, $financialAccount);

            return [
                'id' => $line->id,
                'entry_date' => $line->entry->entry_date->toDateString(),
                'movement_type' => $movementType,
                'direction' => $normalCredit
                    ? (bccomp($effect, '0.0000', 4) >= 0 ? 'LIABILITY_INCREASE' : 'LIABILITY_DECREASE')
                    : (bccomp($effect, '0.0000', 4) >= 0 ? 'IN_BOUND' : 'OUT_BOUND'),
                'amount' => bccomp((string) $line->debit, '0.0000', 4) === 1 ? $line->debit : $line->credit,
                'balance_effect' => $effect,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'description' => $line->description,
                'branch' => $line->branch,
                'journal_entry_id' => $line->journal_entry_id,
                'journal_number' => $line->entry->number,
                'payment_id' => $line->entry->payment_id,
                'account_transfer_id' => $line->entry->financial_account_transfer_id,
                'account_adjustment_id' => $line->entry->financial_account_adjustment_id,
            ];
        });

        return $movements;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreFinancialAccountRequest $request, FinancialAccount $financialAccount)
    {
        DB::transaction(function () use ($request, $financialAccount) {
            $data = $request->validated();
            $hasApplications = array_key_exists('applications', $data);
            $applications = $data['applications'] ?? null;
            $branchIds = $data['branch_ids'] ?? [];
            unset($data['applications'], $data['branch_ids']);
            $financialAccount->update($data);
            $financialAccount->accessibleBranches()->sync($financialAccount->is_company_wide ? [] : $branchIds);
            if ($hasApplications) {
                $this->syncApplications($financialAccount, $applications);
            }
        });

        return $financialAccount->fresh(['ownerBranch', 'accessibleBranches', 'institution', 'chartAccount', 'applications']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FinancialAccount $financialAccount)
    {
        $financialAccount->delete();

        return response()->noContent();
    }

    private function syncApplications(FinancialAccount $account, ?array $applications): void
    {
        if ($applications === null) {
            return;
        }

        $account->applications()->delete();
        $account->applications()->createMany(collect($applications)->map(fn ($application) => [
            'application' => $application,
            'source' => 'manual',
        ])->all());
    }

    private function movementType(JournalLine $line, FinancialAccount $account): string
    {
        $entry = $line->entry;
        if (in_array($entry->kind, ['account_transfer', 'account_transfer_reversal'], true)) {
            $transfer = $entry->financialAccountTransfer;
            $type = (int) $transfer?->source_financial_account_id === (int) $account->id
                ? 'ACCOUNT_TRANSFER_SEND'
                : 'ACCOUNT_TRANSFER_RECEIVE';

            return $entry->kind === 'account_transfer_reversal' ? 'REVERSAL_'.$type : $type;
        }
        if (in_array($entry->kind, ['account_balance_adjustment', 'account_balance_adjustment_reversal'], true)) {
            $type = $entry->financialAccountAdjustment?->payment_obligation_id
                ? 'CREDIT_CARD_SOA_ADJUSTMENT'
                : 'BALANCE_ADJUSTMENT';

            return $entry->kind === 'account_balance_adjustment_reversal' ? 'REVERSAL_'.$type : $type;
        }
        if (in_array($entry->kind, ['end_of_day_settlement', 'end_of_day_reversal'], true)) {
            return $entry->kind === 'end_of_day_reversal'
                ? 'REVERSAL_COLLECTION_TRANSFER'
                : 'COLLECTION_TRANSFER';
        }
        if ($entry->payment) {
            $type = match ($entry->payment->type) {
                'customer' => 'CUSTOMER_PAYMENT',
                'purchase_order' => 'PURCHASE_ORDER_PAYMENT',
                'expense' => 'EXPENSE_PAYMENT',
                default => $entry->payment->allocations->contains(fn ($allocation) => $allocation->obligation?->type === 'credit_card_statement')
                    ? 'CREDIT_CARD_PAYMENT'
                    : 'DIRECT_PAYMENT',
            };

            return $entry->kind === 'reversal' ? 'REVERSAL_'.$type : $type;
        }

        return 'OTHER';
    }
}
