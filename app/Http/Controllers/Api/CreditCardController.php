<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCreditCardAdjustmentRequest;
use App\Http\Requests\StoreCreditCardPaymentRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountAdjustment;
use App\Models\PaymentMethod;
use App\Models\PaymentObligation;
use App\Services\CreditCardCycleService;
use App\Services\CreditCardPaymentService;
use App\Services\FinancialAccountAdjustmentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreditCardController extends Controller
{
    public function __construct(
        private CreditCardCycleService $cycles,
        private CreditCardPaymentService $payments,
        private FinancialAccountAdjustmentService $adjustments,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'financial_institution_id' => ['nullable', 'integer', 'exists:financial_institutions,id'],
            'active' => ['nullable', 'boolean'],
            'as_of' => ['nullable', 'date'],
        ]);
        $asOf = Carbon::parse($filters['as_of'] ?? today()->toDateString())->startOfDay();

        $cards = FinancialAccount::query()
            ->with([
                'institution', 'userAccount', 'ownerBranch', 'accessibleBranches',
                'creditCardStatements.allocations.payment',
            ])
            ->where('kind', 'credit_card')
            ->when($filters['branch_id'] ?? null, fn ($query, $branch) => $query->availableToBranch((int) $branch))
            ->when($filters['financial_institution_id'] ?? null, fn ($query, $institutionId) => $query->where('financial_institution_id', $institutionId))
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->get()
            ->map(fn (FinancialAccount $card) => $this->summary($card, $asOf))
            ->sortBy(fn (array $card) => implode('|', [
                bccomp($card['nearest_payable_amount'], '0.0000', 4) === 1 ? '0' : '1',
                $card['nearest_payable_due_date'] ?? '9999-12-31',
                $card['name'],
            ]))
            ->values();
        $nearestPayable = $cards->first(fn (array $card) => bccomp($card['nearest_payable_amount'], '0.0000', 4) === 1);
        $totalsByCurrency = $cards
            ->groupBy('currency')
            ->map(fn ($currencyCards, $currency) => [
                'currency' => $currency,
                'total_credit_limit' => $this->sum($currencyCards, 'credit_limit'),
                'total_outstanding_balance' => $this->sum($currencyCards, 'outstanding_balance'),
                'total_available_credit' => $this->sum($currencyCards, 'available_credit'),
                'total_amount_due' => $this->sum($currencyCards, 'amount_due'),
            ])
            ->values();
        $singleCurrencyTotals = $totalsByCurrency->count() === 1 ? $totalsByCurrency->first() : null;

        return response()->json([
            'as_of' => $asOf->toDateString(),
            'total' => $cards->count(),
            'summary' => [
                'currency' => $singleCurrencyTotals['currency'] ?? null,
                'total_credit_limit' => $singleCurrencyTotals['total_credit_limit'] ?? null,
                'total_outstanding_balance' => $singleCurrencyTotals['total_outstanding_balance'] ?? null,
                'total_available_credit' => $singleCurrencyTotals['total_available_credit'] ?? null,
                'total_amount_due' => $singleCurrencyTotals['total_amount_due'] ?? null,
                'nearest_payable_due_date' => $nearestPayable['nearest_payable_due_date'] ?? null,
                'nearest_payable_amount' => $nearestPayable['nearest_payable_amount'] ?? '0.0000',
                'by_currency' => $totalsByCurrency,
            ],
            'data' => $cards,
        ]);
    }

    public function show(Request $request, FinancialAccount $financialAccount)
    {
        $filters = $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);
        abort_unless($financialAccount->kind === 'credit_card', 404, 'The selected financial account is not a credit card.');

        $asOf = Carbon::parse($filters['as_of'] ?? today()->toDateString())->startOfDay();
        $financialAccount->load([
            'institution', 'userAccount', 'ownerBranch', 'accessibleBranches',
            'creditCardStatements.allocations.payment',
        ]);

        $details = $this->summary($financialAccount, $asOf);
        $details['statement_history'] = $financialAccount->creditCardStatements
            ->map(fn (PaymentObligation $statement) => $this->statementDetails($statement))
            ->sortByDesc('statement_date')
            ->values();

        return response()->json($details);
    }

    public function paymentMethods(FinancialAccount $financialAccount)
    {
        abort_unless($financialAccount->kind === 'credit_card', 404, 'The selected financial account is not a credit card.');

        $methods = PaymentMethod::query()
            ->forCreditCardPayments()
            ->orderBy('name')
            ->get()
            ->map(fn (PaymentMethod $method) => [
                ...$method->toArray(),
                'compatible_funding_account_kinds' => $method->compatibleFundingAccountKinds(),
            ]);

        return response()->json([
            'credit_card_id' => $financialAccount->id,
            'data' => $methods,
        ]);
    }

    public function fundingAccounts(Request $request, FinancialAccount $financialAccount)
    {
        abort_unless($financialAccount->kind === 'credit_card', 404, 'The selected financial account is not a credit card.');

        $filters = $request->validate([
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('is_active', true),
            ],
        ]);
        $method = PaymentMethod::query()
            ->forCreditCardPayments()
            ->find($filters['payment_method_id']);
        if (! $method) {
            throw ValidationException::withMessages([
                'payment_method_id' => 'Select a payment method applicable to credit-card payments.',
            ]);
        }

        $accounts = FinancialAccount::query()
            ->with('institution', 'ownerBranch', 'accessibleBranches')
            ->where('is_active', true)
            ->where('usable_for_payments', true)
            ->where('currency', $financialAccount->currency)
            ->whereIn('kind', $method->compatibleFundingAccountKinds())
            ->whereHas('chartAccount', fn ($query) => $query->where('type', 'asset'))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->availableToBranch((int) $branchId))
            ->orderBy('name')
            ->get();

        return response()->json([
            'credit_card_id' => $financialAccount->id,
            'payment_method' => $method,
            'branch_id' => isset($filters['branch_id']) ? (int) $filters['branch_id'] : null,
            'data' => $accounts,
        ]);
    }

    public function pay(StoreCreditCardPaymentRequest $request, FinancialAccount $financialAccount)
    {
        $result = $this->payments->pay($financialAccount, $request->validated(), $request->user()->id);

        return response()->json($result, 201);
    }

    public function adjustments(Request $request, FinancialAccount $financialAccount)
    {
        abort_unless($financialAccount->kind === 'credit_card', 404, 'The selected financial account is not a credit card.');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'posted', 'voided'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $adjustments = FinancialAccountAdjustment::query()
            ->with($this->adjustmentRelations())
            ->where('financial_account_id', $financialAccount->id)
            ->whereNotNull('payment_obligation_id')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('adjustment_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('adjustment_date', '<=', $date))
            ->latest('adjustment_date')
            ->latest('id')
            ->paginate()
            ->through(fn (FinancialAccountAdjustment $adjustment) => $this->adjustmentDetails($adjustment));

        return response()->json($adjustments);
    }

    public function storeAdjustment(StoreCreditCardAdjustmentRequest $request, FinancialAccount $financialAccount)
    {
        $adjustment = $this->adjustments->createCreditCardAdjustment(
            $financialAccount,
            $request->validated(),
            $request->user()->id,
        );

        return response()->json($this->adjustmentDetails($adjustment), 201);
    }

    public function showAdjustment(FinancialAccount $financialAccount, FinancialAccountAdjustment $financialAccountAdjustment)
    {
        $this->assertCardAdjustment($financialAccount, $financialAccountAdjustment);

        return response()->json($this->adjustmentDetails(
            $financialAccountAdjustment->load($this->adjustmentRelations())
        ));
    }

    public function voidAdjustment(Request $request, FinancialAccount $financialAccount, FinancialAccountAdjustment $financialAccountAdjustment)
    {
        $this->assertCardAdjustment($financialAccount, $financialAccountAdjustment);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $adjustment = $this->adjustments->void($financialAccountAdjustment, $data['reason'], $request->user()->id);

        return response()->json($this->adjustmentDetails($adjustment));
    }

    private function assertCardAdjustment(FinancialAccount $financialAccount, FinancialAccountAdjustment $adjustment): void
    {
        abort_unless(
            $financialAccount->kind === 'credit_card'
            && (int) $adjustment->financial_account_id === (int) $financialAccount->id
            && $adjustment->payment_obligation_id !== null,
            404,
            'The selected credit-card adjustment was not found.'
        );
    }

    private function adjustmentDetails(FinancialAccountAdjustment $adjustment): array
    {
        $difference = (string) $adjustment->adjustment_amount;

        return [
            'id' => $adjustment->id,
            'number' => $adjustment->number,
            'status' => $adjustment->status,
            'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
            'currency' => $adjustment->currency,
            'expected_amount_due_before' => $adjustment->expected_amount_due_before,
            'paid_amount_before' => $adjustment->paid_amount_before,
            'actual_amount_due' => $adjustment->actual_amount_due,
            'adjustment_amount' => $difference,
            'direction' => bccomp($difference, '0.0000', 4) === 1
                ? 'INCREASE'
                : (bccomp($difference, '0.0000', 4) === -1 ? 'DECREASE' : 'NO_CHANGE'),
            'reference' => $adjustment->reference,
            'reason' => $adjustment->reason,
            'credit_card' => $adjustment->financialAccount,
            'statement' => $adjustment->paymentObligation,
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

    private function adjustmentRelations(): array
    {
        return [
            'financialAccount.institution', 'financialAccount.chartAccount',
            'adjustmentAccount', 'paymentObligation', 'journalEntries.lines.chartAccount',
            'createdBy', 'postedBy', 'voidedBy',
        ];
    }

    private function summary(FinancialAccount $card, Carbon $asOf): array
    {
        [$nextClosingDate] = $this->cycles->cycleDates($asOf->copy(), $card);
        $statements = $card->creditCardStatements
            ->map(fn (PaymentObligation $statement) => $this->statementSummary($statement))
            ->filter(fn (array $statement) => bccomp($statement['balance_due'], '0.0000', 4) === 1)
            ->sortBy('due_date')
            ->values();
        $nearestStatement = $statements->first();
        $nearestDueDate = $nearestStatement
            ? Carbon::parse($nearestStatement['due_date'])->startOfDay()
            : null;
        $nearestPayableAmount = $nearestStatement['balance_due'] ?? '0.0000';
        $amountDue = $statements->reduce(
            fn (string $total, array $statement) => bcadd($total, $statement['balance_due'], 4),
            '0.0000'
        );
        $outstandingBalance = $card->current_balance;
        $availableCredit = $card->available_balance;
        $daysUntilDue = $nearestDueDate ? $asOf->diffInDays($nearestDueDate, false) : null;

        return [
            'id' => $card->id,
            'name' => $card->name,
            'account_holder' => $card->account_holder,
            'user_account' => $card->userAccount,
            'institution' => $card->institution,
            'owner_branch' => $card->ownerBranch,
            'accessible_branches' => $card->accessibleBranches,
            'is_company_wide' => $card->is_company_wide,
            'masked_account_number' => $card->masked_account_number,
            'currency' => $card->currency,
            'credit_limit' => $card->credit_limit,
            'outstanding_balance' => $outstandingBalance,
            'available_credit' => $availableCredit,
            'amount_due' => $amountDue,
            'nearest_payable_due_date' => $nearestDueDate?->toDateString(),
            'nearest_payable_amount' => $nearestPayableAmount,
            'next_due_amount' => $nearestPayableAmount,
            'statement_day' => $card->statement_day,
            'due_day' => $card->due_day,
            'next_closing_date' => $nextClosingDate->toDateString(),
            'nearest_due_date' => $nearestDueDate?->toDateString(),
            'days_until_due' => $daysUntilDue,
            'due_status' => $this->dueStatus($amountDue, $daysUntilDue),
            'is_active' => $card->is_active,
            'unpaid_statements' => $statements,
        ];
    }

    private function statementSummary(PaymentObligation $statement): array
    {
        $total = bcadd(
            bcadd((string) $statement->principal_amount, (string) $statement->interest_amount, 4),
            bcadd((string) $statement->fee_amount, (string) $statement->adjustment_amount, 4),
            4
        );
        $paid = $statement->allocations
            ->filter(fn ($allocation) => $allocation->payment?->status === 'posted')
            ->reduce(fn (string $sum, $allocation) => bcadd($sum, (string) $allocation->amount, 4), '0.0000');
        $balance = bcsub($total, $paid, 4);

        return [
            'id' => $statement->id,
            'number' => $statement->number,
            'statement_date' => $statement->statement_date?->toDateString(),
            'due_date' => $statement->due_date->toDateString(),
            'status' => $statement->status,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'balance_due' => bccomp($balance, '0.0000', 4) === 1 ? $balance : '0.0000',
            'minimum_due' => $statement->minimum_due,
        ];
    }

    private function statementDetails(PaymentObligation $statement): array
    {
        return $this->statementSummary($statement) + [
            'payments' => $statement->allocations
                ->filter(fn ($allocation) => $allocation->payment)
                ->map(fn ($allocation) => [
                    'id' => $allocation->payment->id,
                    'number' => $allocation->payment->number,
                    'payment_date' => $allocation->payment->payment_date?->toDateString(),
                    'status' => $allocation->payment->status,
                    'amount' => $allocation->amount,
                ])
                ->values(),
        ];
    }

    private function dueStatus(string $amountDue, ?int $daysUntilDue): string
    {
        if (bccomp($amountDue, '0.0000', 4) !== 1) {
            return 'NO_AMOUNT_DUE';
        }
        if ($daysUntilDue < 0) {
            return 'OVERDUE';
        }
        if ($daysUntilDue === 0) {
            return 'DUE_TODAY';
        }

        if ($daysUntilDue <= 7) {
            return 'DUE_URGENT';
        }

        return $daysUntilDue <= 10 ? 'DUE_SOON' : 'UPCOMING';
    }

    private function sum($items, string $field): string
    {
        return $items->reduce(
            fn (string $total, array $item) => bcadd($total, (string) ($item[$field] ?? '0'), 4),
            '0.0000'
        );
    }
}
