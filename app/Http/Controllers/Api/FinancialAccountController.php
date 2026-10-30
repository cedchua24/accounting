<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Http\Requests\UpdateFinancialAccountRequest;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinancialAccountController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'payment_method_id' => [
                'nullable',
                'integer',
                Rule::exists('payment_methods', 'id')->where('is_active', true),
            ],
            'application_id' => ['nullable', 'integer', 'exists:financial_account_application_types,id'],
            'application' => ['nullable', 'string', Rule::in(\App\Models\FinancialAccountApplication::selectable())],
            'account_holder' => ['nullable', 'integer', 'exists:user_accounts,id'],
        ]);
        $paymentMethod = isset($filters['payment_method_id'])
            ? PaymentMethod::find($filters['payment_method_id'])
            : null;

        $accounts = FinancialAccount::with('ownerBranch', 'userAccount', 'accessibleBranches', 'institution', 'chartAccount', 'applications')
            ->when($request->branch_id, fn ($query, $branch) => $query->availableToBranch((int) $branch))
            ->when($request->kind, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($filters['account_holder'] ?? null, fn ($query, $holderId) => $query->where('account_holder', $holderId))
            ->when($paymentMethod, fn ($query, PaymentMethod $method) => $query->whereIn('kind', match ($method->type) {
                'cash' => ['cash'],
                'bank_transfer' => ['bank_account', 'checking_account'],
                'cheque' => ['checking_account'],
                'credit_card' => ['credit_card'],
                'e_wallet' => ['e_wallet'],
                default => [],
            }))
            ->when($request->legacy_source, fn ($query, $source) => $query->where('legacy_source', $source))
            ->when($request->legacy_id, fn ($query, $legacyId) => $query->where('legacy_id', $legacyId))
            ->when($filters['application_id'] ?? null, fn ($query, $applicationId) => $query->whereHas('applications', fn ($usage) => $usage->where('application_id', $applicationId)))
            ->when(! isset($filters['application_id']) && ($filters['application'] ?? null), fn ($query) => $query->whereHas('applications.type', fn ($type) => $type->where('code', $filters['application'])))
            ->when($request->boolean('receipts'), fn ($query) => $query->where('usable_for_receipts', true))
            ->when($request->boolean('payments'), fn ($query) => $query->where('usable_for_payments', true))
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')->paginate();

        $accounts->through(function (FinancialAccount $account) {
            $isCreditCard = $account->kind === 'credit_card';
            $data = $account->toArray();

            $data['branch_ids'] = $account->accessibleBranches->modelKeys();
            $data['outstanding_balance'] = $isCreditCard ? $account->current_balance : null;
            $data['available_credit'] = $isCreditCard ? $account->available_balance : null;

            return $data;
        });

        return $accounts;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFinancialAccountRequest $request)
    {
        $account = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $applicationIds = $data['application_ids'] ?? null;
            $branchIds = $data['branch_ids'] ?? [];
            unset($data['application_ids'], $data['branch_ids']);
            $account = FinancialAccount::create($data);
            $this->syncApplications($account, $applicationIds);
            $account->accessibleBranches()->sync($account->is_company_wide ? [] : $branchIds);

            return $account;
        });

        return response()->json($this->accountData(
            $account->load('ownerBranch', 'userAccount', 'accessibleBranches', 'institution', 'chartAccount', 'applications')
        ), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(FinancialAccount $financialAccount)
    {
        return $this->accountData(
            $financialAccount->load('ownerBranch', 'userAccount', 'accessibleBranches', 'institution', 'chartAccount', 'applications')
        );
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'debit' => ['nullable', 'numeric', 'min:0'],
            'credit' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['posted', 'voided'])],
            'event' => ['nullable', Rule::in(['DEBIT', 'CREDIT', 'debit', 'credit'])],
            'sort' => ['nullable', Rule::in(['latest', 'oldest'])],
        ]);
        $dateFrom = $filters['date_from'] ?? $filters['from'] ?? null;
        $dateTo = $filters['date_to'] ?? $filters['to'] ?? null;
        $event = isset($filters['event']) ? strtoupper($filters['event']) : null;
        $sortDirection = ($filters['sort'] ?? 'latest') === 'oldest' ? 'asc' : 'desc';
        $financialAccount->load('chartAccount');

        $movementQuery = JournalLine::query()
            ->where('chart_account_id', $financialAccount->chart_account_id)
            ->with([
                'branch', 'entry.payment.branch', 'entry.payment.counterparty',
                'entry.payment.purchaseOrder', 'entry.payment.salesInvoice', 'entry.payment.expense',
                'entry.payment.lines.paymentMethod', 'entry.payment.lines.confirmedBy', 'entry.payment.allocations.obligation',
                'entry.loanPayment.loan', 'entry.loanPayment.paymentMethod',
                'entry.financialAccountTransfer.sourceAccount', 'entry.financialAccountTransfer.destinationAccount',
                'entry.financialAccountAdjustment.adjustmentAccount', 'entry.financialAccountAdjustment.paymentObligation',
                'entry.customerRefund.originalPayment', 'entry.customerRefund.salesInvoice',
                'entry.customerRefund.customer', 'entry.customerRefund.paymentMethod',
                'entry.endOfDaySettlement.destinationAccount',
            ])
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($dateFrom, fn ($query, $date) => $query->whereHas('entry', fn ($entry) => $entry->whereDate('entry_date', '>=', $date)))
            ->when($dateTo, fn ($query, $date) => $query->whereHas('entry', fn ($entry) => $entry->whereDate('entry_date', '<=', $date)))
            ->when(array_key_exists('debit', $filters), fn ($query) => $query->where('debit', $filters['debit']))
            ->when(array_key_exists('credit', $filters), fn ($query) => $query->where('credit', $filters['credit']))
            ->when($event === 'DEBIT', fn ($query) => $query->where('debit', '>', 0))
            ->when($event === 'CREDIT', fn ($query) => $query->where('credit', '>', 0))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereHas('entry', fn ($entry) => $entry
                ->where(fn ($source) => $source
                    ->whereHas('payment', fn ($payment) => $payment->where('status', $status))
                    ->when($status === 'posted', fn ($loanPayment) => $loanPayment->orWhereNotNull('loan_payment_id'))
                    ->orWhereHas('financialAccountTransfer', fn ($transfer) => $transfer->where('status', $status))
                    ->orWhereHas('financialAccountAdjustment', fn ($adjustment) => $adjustment->where('status', $status))
                    ->orWhereHas('customerRefund', fn ($refund) => $refund->where('status', $status))
                    ->orWhereHas('endOfDaySettlement', fn ($settlement) => $settlement->where('status', $status))
                )))
            ->orderBy(
                JournalEntry::query()
                    ->select('entry_date')
                    ->whereColumn('journal_entries.id', 'journal_lines.journal_entry_id'),
                $sortDirection
            )
            ->orderBy('journal_lines.id', $sortDirection);

        $totals = (clone $movementQuery)
            ->reorder()
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();
        $movements = $movementQuery->paginate();

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
                'event' => bccomp((string) $line->debit, '0.0000', 4) === 1 ? 'DEBIT' : 'CREDIT',
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
                'loan_payment_id' => $line->entry->loan_payment_id,
                'account_transfer_id' => $line->entry->financial_account_transfer_id,
                'account_adjustment_id' => $line->entry->financial_account_adjustment_id,
                'transaction' => $this->transactionDetails($line, $financialAccount),
            ];
        });

        return response()->json([
            ...$movements->toArray(),
            'summary' => [
                'total_debit' => number_format((float) $totals->total_debit, 4, '.', ''),
                'total_credit' => number_format((float) $totals->total_credit, 4, '.', ''),
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFinancialAccountRequest $request, FinancialAccount $financialAccount)
    {
        DB::transaction(function () use ($request, $financialAccount) {
            $data = $request->validated();
            $hasApplications = array_key_exists('application_ids', $data);
            $applicationIds = $data['application_ids'] ?? null;
            $hasBranchIds = array_key_exists('branch_ids', $data);
            $branchIds = $data['branch_ids'] ?? [];
            unset($data['application_ids'], $data['branch_ids']);
            $financialAccount->update($data);
            if ($financialAccount->is_company_wide) {
                $financialAccount->accessibleBranches()->sync([]);
            } elseif ($hasBranchIds) {
                $financialAccount->accessibleBranches()->sync($branchIds);
            }
            if ($hasApplications && $this->applicationsChanged($financialAccount, $applicationIds)) {
                $this->syncApplications($financialAccount, $applicationIds);
            }
        });

        return $this->accountData(
            $financialAccount->fresh(['ownerBranch', 'userAccount', 'accessibleBranches', 'institution', 'chartAccount', 'applications'])
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FinancialAccount $financialAccount)
    {
        $financialAccount->delete();

        return response()->noContent();
    }

    private function syncApplications(FinancialAccount $account, ?array $applicationIds): void
    {
        if ($applicationIds === null) {
            return;
        }

        $account->applications()->delete();
        $account->applications()->createMany(collect($applicationIds)->map(fn ($applicationId) => [
            'application_id' => $applicationId,
            'source' => 'manual',
        ])->all());
    }

    private function applicationsChanged(FinancialAccount $account, ?array $applicationIds): bool
    {
        $submitted = collect($applicationIds ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $existing = $account->applications()->pluck('application_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $submitted !== $existing;
    }

    private function accountData(FinancialAccount $account): array
    {
        return [
            ...$account->toArray(),
            'branch_ids' => $account->accessibleBranches->modelKeys(),
        ];
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
        if (in_array($entry->kind, ['customer_refund', 'customer_refund_reversal'], true)) {
            return $entry->kind === 'customer_refund_reversal'
                ? 'REVERSAL_CUSTOMER_REFUND'
                : 'CUSTOMER_REFUND';
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
        if ($entry->loanPayment) {
            return $entry->loanPayment->loan?->direction === 'lent'
                ? 'LOAN_COLLECTION'
                : 'LOAN_PAYMENT';
        }

        return 'OTHER';
    }

    private function transactionDetails(JournalLine $line, FinancialAccount $account): array
    {
        $entry = $line->entry;

        if ($payment = $entry->payment) {
            $document = match ($payment->type) {
                'customer' => $payment->salesInvoice,
                'purchase_order' => $payment->purchaseOrder,
                'expense' => $payment->expense,
                default => null,
            };
            $sourceType = match ($payment->type) {
                'customer' => 'customer_order',
                'purchase_order' => 'purchase_order',
                'expense' => 'expense',
                default => 'direct_payment',
            };
            $paymentLine = $payment->lines->firstWhere('financial_account_id', $account->id);

            return [
                'source_type' => $sourceType,
                'source_id' => $document?->id ?? $payment->id,
                'source_number' => $document?->number ?? $payment->number,
                'source_status' => $document?->status ?? $payment->status,
                'transaction_status' => $payment->status,
                'payment' => [
                    'id' => $payment->id,
                    'number' => $payment->number,
                    'type' => $payment->type,
                    'direction' => $payment->direction,
                    'status' => $payment->status,
                    'payment_date' => $payment->payment_date?->toDateString(),
                    'memo' => $payment->memo,
                ],
                'payment_method' => $paymentLine?->paymentMethod,
                'payment_line' => $paymentLine ? [
                    'id' => $paymentLine->id,
                    'confirmed' => $paymentLine->confirmed_at !== null,
                    'confirmed_at' => $paymentLine->confirmed_at?->toISOString(),
                    'confirmed_by' => $paymentLine->confirmedBy ? [
                        'id' => $paymentLine->confirmedBy->id,
                        'name' => $paymentLine->confirmedBy->name,
                    ] : null,
                ] : null,
                'reference' => $paymentLine?->reference,
                'counterparty' => $payment->counterparty,
            ];
        }

        if ($loanPayment = $entry->loanPayment) {
            return [
                'source_type' => 'loan_payment',
                'source_id' => $loanPayment->id,
                'source_number' => $loanPayment->loan?->number,
                'source_status' => 'posted',
                'transaction_status' => 'posted',
                'reference' => $loanPayment->reference,
                'payment_method' => $loanPayment->paymentMethod,
                'loan' => $loanPayment->loan,
            ];
        }

        if ($transfer = $entry->financialAccountTransfer) {
            return [
                'source_type' => 'account_transfer',
                'source_id' => $transfer->id,
                'source_number' => $transfer->number,
                'source_status' => $transfer->status,
                'transaction_status' => $transfer->status,
                'reference' => $transfer->reference,
                'source_account' => $transfer->sourceAccount,
                'destination_account' => $transfer->destinationAccount,
                'destination_type' => $transfer->destination_type,
                'transfer_method' => $transfer->transfer_method,
                'external_recipient' => $transfer->destination_type === 'external' ? [
                    'name' => $transfer->recipient_name,
                    'type' => $transfer->recipient_type,
                    'institution_name' => $transfer->external_institution_name,
                    'account_name' => $transfer->external_account_name,
                    'masked_account_number' => $transfer->masked_external_account_number,
                    'contact' => $transfer->recipient_contact,
                ] : null,
            ];
        }

        if ($adjustment = $entry->financialAccountAdjustment) {
            return [
                'source_type' => $adjustment->payment_obligation_id ? 'credit_card_adjustment' : 'balance_adjustment',
                'source_id' => $adjustment->id,
                'source_number' => $adjustment->number,
                'source_status' => $adjustment->status,
                'transaction_status' => $adjustment->status,
                'reference' => $adjustment->reference,
                'reason' => $adjustment->reason,
                'adjustment_account' => $adjustment->adjustmentAccount,
                'payment_obligation' => $adjustment->paymentObligation,
            ];
        }

        if ($refund = $entry->customerRefund) {
            return [
                'source_type' => 'customer_refund',
                'source_id' => $refund->id,
                'source_number' => $refund->number,
                'source_status' => $refund->status,
                'transaction_status' => $refund->status,
                'reference' => $refund->reference,
                'customer_order' => $refund->salesInvoice,
                'payment_method' => $refund->paymentMethod,
                'counterparty' => $refund->customer,
            ];
        }

        if ($settlement = $entry->endOfDaySettlement) {
            return [
                'source_type' => 'collection_transfer',
                'source_id' => $settlement->id,
                'source_number' => $settlement->number,
                'source_status' => $settlement->status,
                'transaction_status' => $settlement->status,
                'destination_account' => $settlement->destinationAccount,
            ];
        }

        return [
            'source_type' => 'journal_entry',
            'source_id' => $entry->id,
            'source_number' => $entry->number,
            'source_status' => null,
            'transaction_status' => 'posted',
        ];
    }
}
