<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanPaymentRequest;
use App\Http\Requests\StoreLoanRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialInstitution;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\PaymentMethod;
use App\Models\UserAccount;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    public function __construct(private LoanService $loans) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['borrowed', 'lent'])],
            'status' => ['nullable', Rule::in(['active', 'paid', 'cancelled'])],
            'counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'financial_institution_id' => ['nullable', 'integer', 'exists:financial_institutions,id'],
            'user_account_id' => ['nullable', 'integer', 'exists:user_accounts,id'],
            'financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'due_before' => ['nullable', 'date'],
            'as_of_date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $asOf = $filters['as_of_date'] ?? now()->toDateString();
        $loans = Loan::query()
            ->with(['counterparty', 'financialInstitution', 'userAccount', 'financialAccount.institution'])
            ->when($filters['direction'] ?? null, fn ($query, $value) => $query->where('direction', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['counterparty_id'] ?? null, fn ($query, $value) => $query->where('counterparty_id', $value))
            ->when($filters['financial_institution_id'] ?? null, fn ($query, $value) => $query->where('financial_institution_id', $value))
            ->when($filters['user_account_id'] ?? null, fn ($query, $value) => $query->where('user_account_id', $value))
            ->when($filters['financial_account_id'] ?? null, fn ($query, $value) => $query->where('financial_account_id', $value))
            ->when($filters['search'] ?? null, fn ($query, $value) => $query->where(function ($search) use ($value) {
                $search->where('number', 'like', "%{$value}%")
                    ->orWhere('party_name', 'like', "%{$value}%")
                    ->orWhereHas('financialInstitution', fn ($institution) => $institution->where('name', 'like', "%{$value}%"))
                    ->orWhereHas('userAccount', fn ($userAccount) => $userAccount->where('name', 'like', "%{$value}%"))
                    ->orWhereHas('counterparty', fn ($counterparty) => $counterparty->where('name', 'like', "%{$value}%"));
            }))
            ->when($filters['due_before'] ?? null, fn ($query, $value) => $query->whereHas(
                'installments', fn ($installments) => $installments->whereDate('due_date', '<=', $value)
            ))
            ->latest('start_date')->latest('id')->paginate($filters['per_page'] ?? 15);

        $loans->getCollection()->transform(fn (Loan $loan) => $this->loans->summary($loan, $asOf));
        $totals = $loans->getCollection()->reduce(function (array $totals, Loan $loan) {
            $totals['principal_amount'] = bcadd($totals['principal_amount'], (string) $loan->principal_amount, 4);
            $totals['interest_amount'] = bcadd($totals['interest_amount'], (string) $loan->interest_amount, 4);
            $totals['total_loan_amount'] = bcadd($totals['total_loan_amount'], (string) $loan->total_loan_amount, 4);
            $totals['balance_amount'] = bcadd($totals['balance_amount'], (string) $loan->balance_amount, 4);
            $totals['interest_expense_amount'] = bcadd(
                $totals['interest_expense_amount'],
                (string) $loan->interest_expense_amount,
                4
            );
            $totals['overdue_interest_expense_amount'] = bcadd(
                $totals['overdue_interest_expense_amount'],
                (string) $loan->overdue_interest_expense_amount,
                4
            );
            $totals['loan_count']++;

            return $totals;
        }, [
            'principal_amount' => '0.0000',
            'interest_amount' => '0.0000',
            'total_loan_amount' => '0.0000',
            'balance_amount' => '0.0000',
            'interest_expense_amount' => '0.0000',
            'overdue_interest_expense_amount' => '0.0000',
            'loan_count' => 0,
        ]);

        $response = $loans->toArray();
        $response['totals'] = $totals;

        return response()->json($response);
    }

    public function creationOptions(Request $request)
    {
        $filters = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $currency = isset($filters['currency']) ? strtoupper($filters['currency']) : null;
        $accounts = FinancialAccount::query()
            ->with(['institution', 'ownerBranch', 'userAccount'])
            ->where('is_active', true)
            ->whereIn('kind', ['cash', 'bank_account', 'checking_account', 'credit_card'])
            ->when($currency, fn ($query, $value) => $query->where('currency', $value))
            ->orderBy('name')
            ->get();
        $bankInstitutions = FinancialInstitution::query()
            ->where('type', 'bank')
            ->where('is_active', true)
            ->withCount(['accounts as compatible_accounts_count' => fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('kind', ['bank_account', 'checking_account'])
                ->when($currency, fn ($accounts, $value) => $accounts->where('currency', $value))])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type']);
        $userAccounts = UserAccount::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'type', 'name']);

        $options = collect([
            [
                'value' => 'company', 'label' => 'External person / company', 'internal_only' => false,
                'requires_financial_institution' => false, 'requires_user_account' => false,
                'requires_financial_account' => false, 'kinds' => [],
            ],
            [
                'value' => 'bank', 'label' => 'Bank', 'internal_only' => true,
                'requires_financial_institution' => true, 'requires_user_account' => true,
                'requires_financial_account' => false, 'kinds' => [],
            ],
            [
                'value' => 'credit_card', 'label' => 'Credit Card', 'internal_only' => true,
                'requires_financial_institution' => false, 'requires_user_account' => false,
                'requires_financial_account' => true, 'kinds' => ['credit_card'],
            ],
        ])->map(function (array $option) use ($accounts, $userAccounts) {
            $option['accounts'] = $accounts
                ->whereIn('kind', $option['kinds'])
                ->values()
                ->map(fn (FinancialAccount $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'kind' => $account->kind,
                    'currency' => $account->currency,
                    'institution' => $account->institution?->name,
                    'institution_id' => $account->financial_institution_id,
                    'owner_branch' => $account->ownerBranch?->name,
                    'owner_branch_id' => $account->owner_branch_id,
                    'masked_account_number' => $account->masked_account_number,
                    'account_holder' => $account->account_holder,
                    'user_account' => $account->userAccount ? [
                        'id' => $account->userAccount->id,
                        'code' => $account->userAccount->code,
                        'type' => $account->userAccount->type,
                        'name' => $account->userAccount->name,
                    ] : null,
                ]);
            $option['user_accounts'] = $option['value'] === 'bank'
                ? $userAccounts
                : $option['accounts']->pluck('user_account')->filter()->unique('id')->values();
            unset($option['kinds']);

            return $option;
        });

        return response()->json([
            'borrower_types' => $options,
            'financial_institutions' => $bankInstitutions,
            'user_accounts' => $userAccounts,
            'currency' => $currency,
        ]);
    }

    public function store(StoreLoanRequest $request)
    {
        return response()->json($this->loans->create($request->validated()), 201);
    }

    public function show(Request $request, Loan $loan)
    {
        $data = $request->validate(['as_of_date' => ['nullable', 'date']]);

        return $this->loans->present($loan, $data['as_of_date'] ?? now()->toDateString());
    }

    public function upcomingDues(Request $request)
    {
        $filters = $request->validate([
            'day_from' => ['nullable', 'date_format:Y-m-d'],
            'day_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:day_from'],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
            'direction' => ['nullable', Rule::in(['borrowed', 'lent'])],
            'counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'financial_institution_id' => ['nullable', 'integer', 'exists:financial_institutions,id'],
            'user_account_id' => ['nullable', 'integer', 'exists:user_accounts,id'],
            'financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'include_overdue' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $asOf = CarbonImmutable::createFromFormat(
            'Y-m-d',
            $filters['as_of_date'] ?? CarbonImmutable::today()->toDateString()
        )->startOfDay();
        $dayFrom = $filters['day_from'] ?? $asOf->toDateString();
        $dayTo = $filters['day_to'] ?? $asOf->addDays(30)->toDateString();
        $includeOverdue = (bool) ($filters['include_overdue'] ?? true);
        $limit = (int) ($filters['limit'] ?? 20);

        $items = Loan::query()
            ->where('status', '!=', 'cancelled')
            ->when($filters['direction'] ?? null, fn ($query, $value) => $query->where('direction', $value))
            ->when($filters['counterparty_id'] ?? null, fn ($query, $value) => $query->where('counterparty_id', $value))
            ->when($filters['financial_institution_id'] ?? null, fn ($query, $value) => $query->where('financial_institution_id', $value))
            ->when($filters['user_account_id'] ?? null, fn ($query, $value) => $query->where('user_account_id', $value))
            ->when($filters['financial_account_id'] ?? null, fn ($query, $value) => $query->where('financial_account_id', $value))
            ->whereHas('installments', fn ($query) => $query
                ->whereDate('due_date', '<=', $dayTo)
                ->when(! $includeOverdue, fn ($installments) => $installments
                    ->whereDate('due_date', '>=', $dayFrom)))
            ->get()
            ->flatMap(function (Loan $loan) use ($asOf, $dayFrom, $dayTo, $includeOverdue) {
                $loan = $this->loans->present($loan, $asOf->toDateString());
                $contractPaid = max(
                    0,
                    (float) $loan->total_amount - (float) $loan->scheduled_balance
                );
                $loanDetails = [
                    'id' => $loan->id,
                    'number' => $loan->number,
                    'direction' => $loan->direction,
                    'payment_direction' => $loan->direction === 'lent' ? 'inflow' : 'outflow',
                    'status' => $loan->status,
                    'borrower_type' => $loan->borrower_type,
                    'party' => $loan->borrower_name,
                    'counterparty_id' => $loan->counterparty_id,
                    'counterparty' => $loan->counterparty,
                    'financial_institution_id' => $loan->financial_institution_id,
                    'financial_institution' => $loan->financialInstitution,
                    'user_account_id' => $loan->user_account_id,
                    'user_account' => $loan->userAccount,
                    'user_account_name' => $loan->userAccount?->name,
                    'display_person' => $loan->userAccount?->name ?? $loan->borrower_name,
                    'financial_account_id' => $loan->financial_account_id,
                    'financial_account' => $loan->financialAccount,
                    'funding_type' => $loan->financialAccount?->kind ?? $loan->borrower_type,
                    'currency' => $loan->currency,
                    'start_date' => $loan->start_date?->toDateString(),
                    'first_due_date' => $loan->first_due_date?->toDateString(),
                    'term_months' => $loan->term_months,
                    'principal_amount' => $loan->principal_amount,
                    'interest_amount' => $loan->total_interest_amount,
                    'total_loan_amount' => $loan->total_amount,
                    'amount_paid' => number_format($contractPaid, 4, '.', ''),
                    'scheduled_balance' => $loan->scheduled_balance,
                    'overdue_interest_balance' => $loan->overdue_interest_balance,
                    'balance_amount' => $loan->balance_due,
                    'overpayment_amount' => $loan->overpayment_amount,
                    'interest_paid_amount' => $loan->interest_paid_amount,
                    'overdue_interest_paid_amount' => $loan->overdue_interest_paid_amount,
                    'total_interest_paid_amount' => $loan->total_interest_paid_amount,
                    'overdue_interest_rate' => $loan->overdue_interest_rate,
                    'overdue_interest_period' => $loan->overdue_interest_period,
                    'payment_count' => $loan->payments->count(),
                    'notes' => $loan->notes,
                ];

                return $loan->installments
                    ->filter(function ($installment) use ($asOf, $dayFrom, $dayTo, $includeOverdue) {
                        $withinRange = $installment->due_date->betweenIncluded($dayFrom, $dayTo);
                        $isEarlierOverdue = $includeOverdue && $installment->due_date->lt($asOf);

                        return ($withinRange || $isEarlierOverdue)
                            && $installment->due_date->lte($dayTo)
                            && bccomp($installment->total_due, '0.0000', 4) === 1;
                    })
                    ->map(function ($installment) use ($loan, $asOf, $loanDetails) {
                        $dueDate = CarbonImmutable::parse($installment->due_date)->startOfDay();
                        $daysUntilDue = $asOf->diffInDays($dueDate, false);

                        return [
                            'id' => $installment->id,
                            'loan_id' => $loan->id,
                            'loan_number' => $loan->number,
                            'direction' => $loan->direction,
                            'payment_direction' => $loan->direction === 'lent' ? 'inflow' : 'outflow',
                            'party' => $loan->borrower_name,
                            'counterparty_id' => $loan->counterparty_id,
                            'financial_institution_id' => $loan->financial_institution_id,
                            'financial_institution' => $loan->financialInstitution,
                            'user_account_id' => $loan->user_account_id,
                            'user_account' => $loan->userAccount,
                            'user_account_name' => $loan->userAccount?->name,
                            'display_person' => $loan->userAccount?->name ?? $loan->borrower_name,
                            'financial_account_id' => $loan->financial_account_id,
                            'financial_account' => $loan->financialAccount?->name ?? $loan->financialInstitution?->name,
                            'funding_type' => $loan->financialAccount?->kind ?? $loan->borrower_type,
                            'installment_number' => $installment->installment_number,
                            'term_months' => $loan->term_months,
                            'currency' => $loan->currency,
                            'due_date' => $dueDate->toDateString(),
                            'due' => $dueDate->format('M j, Y'),
                            'days_until_due' => $daysUntilDue,
                            'is_overdue' => $daysUntilDue < 0,
                            'urgency' => $this->dueUrgency($daysUntilDue),
                            'status' => $installment->status,
                            'principal_due' => $installment->principal_due,
                            'interest_due' => $installment->interest_due,
                            'scheduled_amount' => $installment->scheduled_amount,
                            'paid_amount' => $installment->paid_amount,
                            'payment_received_amount' => $installment->payment_received_amount,
                            'scheduled_paid_amount' => $installment->scheduled_paid_amount,
                            'principal_paid' => $installment->principal_paid,
                            'interest_paid' => $installment->interest_paid,
                            'overpayment_amount' => $installment->overpayment_amount,
                            'overpayment_applied_amount' => $installment->overpayment_applied_amount,
                            'overdue_interest_paid_amount' => $installment->overdue_interest_paid_amount,
                            'outstanding_amount' => $installment->outstanding_amount,
                            'overdue_interest_due' => $installment->overdue_interest_due,
                            'amount_due' => $installment->total_due,
                            'payment_entries' => $installment->payment_entries,
                            'loan' => $loanDetails,
                        ];
                    });
            })
            ->sortBy([['due_date', 'asc'], ['loan_id', 'asc'], ['installment_number', 'asc']])
            ->values();

        $overdueItems = $items->where('is_overdue', true);
        $dueWithinSevenDays = $items->filter(fn (array $item) => $item['days_until_due'] >= 0
            && $item['days_until_due'] <= 7);

        return response()->json([
            'data' => $items->take($limit)->values(),
            'total' => $items->count(),
            'total_installments' => $items->count(),
            'total_amount_due' => $items->reduce(
                fn (string $total, array $item) => bcadd($total, $item['amount_due'], 4),
                '0.0000'
            ),
            'overdue_count' => $overdueItems->count(),
            'overdue_amount' => $overdueItems->reduce(
                fn (string $total, array $item) => bcadd($total, $item['amount_due'], 4),
                '0.0000'
            ),
            'due_within_7_days_count' => $dueWithinSevenDays->count(),
            'due_within_7_days_amount' => $dueWithinSevenDays->reduce(
                fn (string $total, array $item) => bcadd($total, $item['amount_due'], 4),
                '0.0000'
            ),
            'day_from' => $dayFrom,
            'day_to' => $dayTo,
            'as_of_date' => $asOf->toDateString(),
            'include_overdue' => $includeOverdue,
            'limit' => $limit,
        ]);
    }

    public function update(StoreLoanRequest $request, Loan $loan)
    {
        return $this->loans->update($loan, $request->validated());
    }

    public function destroy(Loan $loan)
    {
        abort_if($loan->payments()->exists(), 409, 'A loan with payments cannot be deleted.');
        $loan->delete();

        return response()->noContent();
    }

    public function cancel(Loan $loan)
    {
        abort_if($loan->payments()->exists(), 409, 'A loan with payments cannot be cancelled.');
        abort_if($loan->status !== 'active', 409, 'Only an active loan can be cancelled.');
        $loan->update(['status' => 'cancelled']);

        return $this->loans->present($loan->fresh(), now()->toDateString());
    }

    public function payments(Loan $loan)
    {
        return $loan->payments()
            ->with('paymentMethod', 'financialAccount.institution', 'allocations.installment')
            ->paginate();
    }

    public function paymentMethods(Loan $loan)
    {
        $methods = PaymentMethod::query()
            ->forLoanPayments()
            ->orderBy('name')
            ->get()
            ->map(fn (PaymentMethod $method) => [
                ...$method->toArray(),
                'compatible_financial_account_kinds' => $method->compatibleFundingAccountKinds(),
            ]);

        return response()->json([
            'loan_id' => $loan->id,
            'currency' => $loan->currency,
            'excess_treatments' => [
                [
                    'value' => 'overdue_interest',
                    'label' => 'Overdue interest',
                    'description' => 'Record the extra amount as overdue interest (expense for borrowed loans).',
                ],
                [
                    'value' => 'overpayment',
                    'label' => 'Overpayment',
                    'description' => 'Waive the current overdue interest and reduce the next installment.',
                ],
            ],
            'data' => $methods,
        ]);
    }

    public function paymentAccounts(Request $request, Loan $loan)
    {
        $filters = $request->validate([
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'financial_institution_id' => ['nullable', 'integer', 'exists:financial_institutions,id'],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('is_active', true),
            ],
        ]);
        $method = PaymentMethod::query()->forLoanPayments()->find($filters['payment_method_id']);
        if (! $method) {
            throw ValidationException::withMessages([
                'payment_method_id' => 'Select Cash, Bank Transfer, Cheque, or E-Wallet.',
            ]);
        }

        $baseQuery = FinancialAccount::query()
            ->where('is_active', true)
            ->where('usable_for_payments', true)
            ->where('currency', $loan->currency)
            ->whereIn('kind', $method->compatibleFundingAccountKinds())
            ->whereHas('chartAccount', fn ($query) => $query->where('type', 'asset'))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->availableToBranch((int) $branchId));

        $institutions = (clone $baseQuery)
            ->whereNotNull('financial_institution_id')
            ->with('institution')
            ->get()
            ->pluck('institution')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
        $accounts = $baseQuery
            ->with('institution', 'userAccount', 'ownerBranch', 'accessibleBranches')
            ->when(
                $filters['financial_institution_id'] ?? null,
                fn ($query, $institutionId) => $query->where('financial_institution_id', $institutionId)
            )
            ->orderBy('name')
            ->get();

        return response()->json([
            'loan_id' => $loan->id,
            'currency' => $loan->currency,
            'payment_method' => $method,
            'financial_institutions' => $institutions,
            'financial_institution_id' => isset($filters['financial_institution_id'])
                ? (int) $filters['financial_institution_id']
                : null,
            'branch_id' => isset($filters['branch_id']) ? (int) $filters['branch_id'] : null,
            'data' => $accounts,
        ]);
    }

    public function storePayment(StoreLoanPaymentRequest $request, Loan $loan)
    {
        return response()->json($this->loans->recordPayment(
            $loan,
            $request->validated(),
            $request->user()?->id,
        ), 201);
    }

    public function destroyPayment(Loan $loan, LoanPayment $loanPayment)
    {
        abort_unless((int) $loanPayment->loan_id === (int) $loan->id, 404);
        $this->loans->deletePayment($loanPayment);

        return response()->noContent();
    }

    private function dueUrgency(int $daysUntilDue): string
    {
        if ($daysUntilDue < 0) {
            $days = abs($daysUntilDue);

            return $days === 1 ? '1 day overdue' : "{$days} days overdue";
        }
        if ($daysUntilDue === 0) {
            return 'Due today';
        }

        return $daysUntilDue === 1 ? 'Due in 1 day' : "Due in {$daysUntilDue} days";
    }
}
