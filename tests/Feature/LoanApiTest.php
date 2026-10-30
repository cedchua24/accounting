<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Counterparty;
use App\Models\FinancialAccount;
use App\Models\FinancialInstitution;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_twelve_month_loan_with_exact_equal_installments(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->account('Cash on Hand', 'cash');
        $person = Counterparty::create([
            'type' => 'customer', 'code' => 'PERSON-001', 'name' => 'Juan Dela Cruz', 'is_active' => true,
        ]);

        $response = $this->postJson('/api/loans', [
            'number' => 'LOAN-001',
            'direction' => 'lent',
            'counterparty_id' => $person->id,
            'financial_account_id' => $account->id,
            'start_date' => '2026-10-22',
            'first_due_date' => '2026-11-22',
            'currency' => 'php',
            'principal_amount' => 1000000,
            'total_interest_amount' => 200000,
            'term_months' => 12,
        ])->assertCreated()
            ->assertJsonPath('total_amount', '1200000.0000')
            ->assertJsonPath('scheduled_balance', '1200000.0000')
            ->assertJsonCount(12, 'installments');

        foreach ($response->json('installments') as $installment) {
            $this->assertSame('100000.0000', $installment['scheduled_amount']);
        }
        $this->assertSame('2027-10-22T00:00:00.000000Z', $response->json('installments.11.due_date'));
    }

    public function test_it_supports_a_named_person_credit_card_and_overdue_interest(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $card = $this->account('Company Card', 'credit_card');
        $cash = $this->account('Loan Payment Cash', 'cash');
        $cashMethod = $this->paymentMethod('cash');

        $loan = $this->postJson('/api/loans', [
            'direction' => 'lent',
            'party_name' => 'Maria Santos',
            'financial_account_id' => $card->id,
            'start_date' => '2026-01-01',
            'first_due_date' => '2026-02-01',
            'currency' => 'PHP',
            'principal_amount' => 100000,
            'total_interest_amount' => 20000,
            'term_months' => 12,
            'overdue_interest_rate' => 12,
            'overdue_interest_period' => 'annual',
        ])->assertCreated()->json();

        $this->getJson("/api/loans/{$loan['id']}?as_of_date=2026-03-03")
            ->assertOk()
            ->assertJsonPath('party_name', 'Maria Santos')
            ->assertJsonPath('installments.0.status', 'overdue')
            ->assertJsonPath('installments.0.overdue_interest_due', '98.6301');

        $this->postJson("/api/loans/{$loan['id']}/payments", [
            'payment_method_id' => $cashMethod->id,
            'financial_account_id' => $cash->id,
            'payment_date' => '2026-03-03',
            'amount' => 10098.6301,
            'reference' => 'PAY-001',
        ])->assertCreated()
            ->assertJsonPath('allocations.0.overdue_interest_amount', '98.6301')
            ->assertJsonPath('allocations.0.interest_amount', '1666.6666')
            ->assertJsonPath('allocations.0.principal_amount', '8333.3334');

        $this->getJson("/api/loans/{$loan['id']}?as_of_date=2026-03-03")
            ->assertOk()
            ->assertJsonPath('installments.0.status', 'paid')
            ->assertJsonPath('installments.0.total_due', '0.0000');
    }

    public function test_it_rejects_payment_over_the_payoff_amount(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $bank = $this->account('Operating Bank', 'bank_account');
        $bankMethod = $this->paymentMethod('bank_transfer');
        $loan = $this->postJson('/api/loans', [
            'direction' => 'borrowed', 'party_name' => 'Private Lender',
            'financial_account_id' => $bank->id, 'start_date' => '2026-01-01',
            'first_due_date' => '2026-02-01', 'currency' => 'PHP',
            'principal_amount' => 1000, 'total_interest_amount' => 0, 'term_months' => 1,
        ])->assertCreated()->json();

        $this->postJson("/api/loans/{$loan['id']}/payments", [
            'payment_method_id' => $bankMethod->id,
            'financial_account_id' => $bank->id, 'payment_date' => '2026-01-15', 'amount' => 1000.0001,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('loan_payments', 0);
    }

    public function test_borrowed_loan_payment_reduces_selected_financial_account_balance(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $wallet = $this->account('Loan Payment Wallet', 'e_wallet');
        $wallet->forceFill(['current_balance' => 500000])->save();
        $walletMethod = $this->paymentMethod('e_wallet');
        $loan = $this->postJson('/api/loans', [
            'direction' => 'borrowed', 'party_name' => 'Private Lender',
            'start_date' => '2026-10-01', 'first_due_date' => '2026-11-27',
            'currency' => 'PHP', 'principal_amount' => 200000,
            'total_interest_amount' => 0, 'term_months' => 2,
        ])->assertCreated()->json();

        $payment = $this->postJson("/api/loans/{$loan['id']}/payments", [
            'payment_method_id' => $walletMethod->id,
            'financial_account_id' => $wallet->id,
            'excess_treatment' => 'overpayment',
            'payment_date' => '2026-10-27',
            'amount' => 110000,
        ])->assertCreated()
            ->assertJsonPath('amount', '110000.0000')
            ->assertJsonPath('overpayment_amount', '10000.0000')
            ->assertJsonCount(1, 'journal_entries')
            ->json();

        $this->assertSame('390000.0000', $wallet->fresh()->current_balance);
        $this->assertSame('390000.0000', $wallet->fresh()->available_balance);
        $this->assertDatabaseHas('journal_entries', ['loan_payment_id' => $payment['id']]);

        $this->deleteJson("/api/loans/{$loan['id']}/payments/{$payment['id']}")
            ->assertNoContent();
        $this->assertSame('500000.0000', $wallet->fresh()->current_balance);
        $this->assertSame('500000.0000', $wallet->fresh()->available_balance);
    }

    public function test_as_of_date_excludes_future_payments(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cash = $this->account('Petty Cash', 'cash');
        $cashMethod = $this->paymentMethod('cash');
        $loan = $this->postJson('/api/loans', [
            'direction' => 'lent', 'party_name' => 'Pedro Reyes',
            'financial_account_id' => $cash->id, 'start_date' => '2026-01-01',
            'first_due_date' => '2026-02-01', 'currency' => 'PHP',
            'principal_amount' => 12000, 'total_interest_amount' => 0, 'term_months' => 12,
        ])->assertCreated()->json();

        $this->postJson("/api/loans/{$loan['id']}/payments", [
            'payment_method_id' => $cashMethod->id,
            'financial_account_id' => $cash->id, 'payment_date' => '2026-03-01', 'amount' => 1000,
        ])->assertCreated();

        $this->getJson("/api/loans/{$loan['id']}?as_of_date=2026-02-15")
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonCount(0, 'payments')
            ->assertJsonPath('installments.0.paid_amount', '0.0000')
            ->assertJsonPath('balance_due', '12000.0000');
    }

    public function test_list_returns_transaction_summaries_and_id_fetches_full_details(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $bank = $this->account('Loan Bank', 'bank_account');
        $created = $this->postJson('/api/loans', [
            'number' => 'LOAN-LIST-001', 'direction' => 'lent', 'party_name' => 'Ana Lim',
            'financial_account_id' => $bank->id, 'start_date' => '2026-10-01',
            'first_due_date' => '2026-11-01', 'currency' => 'PHP',
            'principal_amount' => 1000000, 'total_interest_amount' => 200000, 'term_months' => 12,
        ])->assertCreated()->json();

        $listItem = $this->getJson('/api/loans/list?search=Ana&as_of_date=2026-10-22')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $created['id'])
            ->assertJsonPath('data.0.party', 'Ana Lim')
            ->assertJsonPath('data.0.funding_type', 'bank_account')
            ->assertJsonPath('data.0.installment_amount', '100000.0000')
            ->assertJsonPath('data.0.interest_amount', '200000.0000')
            ->assertJsonPath('data.0.total_loan_amount', '1200000.0000')
            ->assertJsonPath('data.0.balance_amount', '1200000.0000')
            ->assertJsonPath('data.0.interest_expense_amount', '0.0000')
            ->assertJsonPath('data.0.overdue_interest_expense_amount', '0.0000')
            ->assertJsonPath('data.0.balance_due', '1200000.0000')
            ->assertJsonPath('totals.principal_amount', '1000000.0000')
            ->assertJsonPath('totals.interest_amount', '200000.0000')
            ->assertJsonPath('totals.total_loan_amount', '1200000.0000')
            ->assertJsonPath('totals.balance_amount', '1200000.0000')
            ->assertJsonPath('totals.interest_expense_amount', '0.0000')
            ->assertJsonPath('totals.overdue_interest_expense_amount', '0.0000')
            ->assertJsonPath('totals.loan_count', 1)
            ->assertJsonMissingPath('data.0.installments')
            ->assertJsonMissingPath('data.0.payments')
            ->json('data.0');

        $this->getJson("/api/loans/{$listItem['id']}?as_of_date=2026-10-22")
            ->assertOk()
            ->assertJsonPath('id', $listItem['id'])
            ->assertJsonCount(12, 'installments')
            ->assertJsonPath('installments.0.scheduled_amount', '100000.0000')
            ->assertJsonCount(0, 'payments');
    }

    public function test_loan_list_includes_internal_user_account_when_applicable(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $institution = FinancialInstitution::create([
            'code' => 'LIST-BDO',
            'name' => 'BDO',
            'type' => 'bank',
            'is_active' => true,
        ]);
        $holder = UserAccount::create([
            'code' => 'LIST-CARL',
            'type' => 'individual',
            'name' => 'CARL CEDRICK P. CHUA',
            'is_active' => true,
        ]);

        $loan = $this->postJson('/api/loans', [
            'number' => 'LOAN-INTERNAL-HOLDER',
            'direction' => 'borrowed',
            'borrower_type' => 'bank',
            'financial_institution_id' => $institution->id,
            'user_account_id' => $holder->id,
            'start_date' => '2026-10-01',
            'first_due_date' => '2026-11-01',
            'currency' => 'PHP',
            'principal_amount' => 500000,
            'total_interest_amount' => 100000,
            'term_months' => 6,
        ])->assertCreated()->json();

        $this->getJson('/api/loans/list?search=LOAN-INTERNAL-HOLDER&as_of_date=2026-10-29')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $loan['id'])
            ->assertJsonPath('data.0.user_account_id', $holder->id)
            ->assertJsonPath('data.0.user_account.id', $holder->id)
            ->assertJsonPath('data.0.user_account.name', 'CARL CEDRICK P. CHUA')
            ->assertJsonPath('data.0.user_account_name', 'CARL CEDRICK P. CHUA')
            ->assertJsonPath('data.0.principal_amount', '500000.0000')
            ->assertJsonPath('data.0.interest_amount', '100000.0000')
            ->assertJsonPath('data.0.total_loan_amount', '600000.0000')
            ->assertJsonPath('data.0.balance_amount', '600000.0000');

        $this->getJson('/api/loan-installments/upcoming-dues?day_from=2026-10-29'
            .'&day_to=2026-11-29&as_of_date=2026-10-29')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.loan_id', $loan['id'])
            ->assertJsonPath('data.0.party', 'BDO')
            ->assertJsonPath('data.0.user_account_id', $holder->id)
            ->assertJsonPath('data.0.user_account.id', $holder->id)
            ->assertJsonPath('data.0.user_account.name', 'CARL CEDRICK P. CHUA')
            ->assertJsonPath('data.0.user_account_name', 'CARL CEDRICK P. CHUA')
            ->assertJsonPath('data.0.display_person', 'CARL CEDRICK P. CHUA')
            ->assertJsonPath('data.0.loan.user_account_id', $holder->id)
            ->assertJsonPath('data.0.loan.user_account.name', 'CARL CEDRICK P. CHUA')
            ->assertJsonPath('data.0.loan.user_account_name', 'CARL CEDRICK P. CHUA')
            ->assertJsonPath('data.0.loan.display_person', 'CARL CEDRICK P. CHUA');
    }

    public function test_loan_responses_list_payment_method_and_bank_for_each_partial_payment(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cash = $this->account('Partial Cash', 'cash');
        $bank = $this->account('Partial Bank', 'bank_account');
        $institution = FinancialInstitution::create([
            'code' => 'PARTIAL-BDO',
            'name' => 'BDO',
            'type' => 'bank',
            'is_active' => true,
        ]);
        $bank->update(['financial_institution_id' => $institution->id]);
        $cashMethod = $this->paymentMethod('cash');
        $bankMethod = $this->paymentMethod('bank_transfer');

        $loan = $this->postJson('/api/loans', [
            'number' => 'LOAN-PARTIAL-SOURCES',
            'direction' => 'borrowed',
            'party_name' => 'Partial Payment Lender',
            'financial_account_id' => $bank->id,
            'start_date' => '2026-01-01',
            'first_due_date' => '2026-02-01',
            'currency' => 'PHP',
            'principal_amount' => 200000,
            'total_interest_amount' => 0,
            'term_months' => 2,
        ])->assertCreated()->json();

        $this->postJson("/api/loans/{$loan['id']}/payments", [
            'payment_method_id' => $cashMethod->id,
            'financial_account_id' => $cash->id,
            'payment_date' => '2026-02-01',
            'amount' => 40000,
        ])->assertCreated();
        $this->postJson("/api/loans/{$loan['id']}/payments", [
            'payment_method_id' => $bankMethod->id,
            'financial_account_id' => $bank->id,
            'payment_date' => '2026-02-02',
            'amount' => 60000,
        ])->assertCreated();

        $this->getJson("/api/loans/{$loan['id']}?as_of_date=2026-02-02")
            ->assertOk()
            ->assertJsonCount(2, 'payment_entries')
            ->assertJsonPath('payment_entries.0.amount', '40000.0000')
            ->assertJsonPath('payment_entries.0.payment_method.name', 'Cash')
            ->assertJsonPath('payment_entries.0.financial_account.name', 'Partial Cash')
            ->assertJsonPath('payment_entries.0.financial_institution', null)
            ->assertJsonPath('payment_entries.1.amount', '60000.0000')
            ->assertJsonPath('payment_entries.1.payment_method.name', 'Bank Transfer')
            ->assertJsonPath('payment_entries.1.financial_account.name', 'Partial Bank')
            ->assertJsonPath('payment_entries.1.financial_institution.name', 'BDO')
            ->assertJsonCount(2, 'installments.0.payment_entries')
            ->assertJsonPath('installments.0.payment_entries.0.allocated_amount', '40000.0000')
            ->assertJsonPath('installments.0.payment_entries.1.allocated_amount', '60000.0000');

        $summaries = $this->getJson('/api/loans/list?search=LOAN-PARTIAL-SOURCES&as_of_date=2026-02-02')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(2, 'data.0.payment_entries')
            ->json('data.0.payment_entries');
        $this->assertSame('Cash', $summaries[0]['payment_method']['name']);
        $this->assertSame('BDO', $summaries[1]['financial_institution']['name']);
    }

    public function test_it_adjusts_an_installment_and_keeps_an_audit_history(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $cash = $this->account('Adjustment Cash', 'cash');
        $cashMethod = $this->paymentMethod('cash');
        $loan = $this->postJson('/api/loans', [
            'number' => 'LOAN-ADJUSTMENT-001',
            'direction' => 'borrowed',
            'party_name' => 'Adjustment Lender',
            'financial_account_id' => $cash->id,
            'start_date' => '2026-01-01',
            'first_due_date' => '2026-02-01',
            'currency' => 'PHP',
            'principal_amount' => 200000,
            'total_interest_amount' => 20000,
            'term_months' => 2,
        ])->assertCreated()->json();
        $secondInstallment = $loan['installments'][1];

        $this->patchJson("/api/loans/{$loan['id']}/installments/{$secondInstallment['id']}", [
            'due_date' => '2026-03-15',
            'principal_due' => 120000,
            'interest_due' => 15000,
            'reason' => 'Lender approved a revised final installment.',
            'as_of_date' => '2026-01-15',
        ])->assertOk()
            ->assertJsonPath('adjustment.adjusted_by', $user->id)
            ->assertJsonPath('adjustment.old_due_date', '2026-03-01T00:00:00.000000Z')
            ->assertJsonPath('adjustment.new_due_date', '2026-03-15T00:00:00.000000Z')
            ->assertJsonPath('adjustment.old_principal_due', '100000.0000')
            ->assertJsonPath('adjustment.new_principal_due', '120000.0000')
            ->assertJsonPath('adjustment.old_interest_due', '10000.0000')
            ->assertJsonPath('adjustment.new_interest_due', '15000.0000')
            ->assertJsonPath('loan.principal_amount', '220000.0000')
            ->assertJsonPath('loan.total_interest_amount', '25000.0000')
            ->assertJsonPath('loan.total_amount', '245000.0000')
            ->assertJsonPath('loan.balance_due', '245000.0000')
            ->assertJsonPath('loan.installments.1.due_date', '2026-03-15T00:00:00.000000Z')
            ->assertJsonPath('loan.installments.1.scheduled_amount', '135000.0000');

        $this->getJson("/api/loans/{$loan['id']}/installments/{$secondInstallment['id']}/adjustments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Lender approved a revised final installment.')
            ->assertJsonPath('data.0.adjusted_by', $user->id)
            ->assertJsonPath('data.0.adjusted_by_user.id', $user->id);

        $this->postJson("/api/loans/{$loan['id']}/payments", [
            'payment_method_id' => $cashMethod->id,
            'financial_account_id' => $cash->id,
            'payment_date' => '2026-02-01',
            'amount' => 60000,
        ])->assertCreated();

        $firstInstallment = $loan['installments'][0];
        $this->patchJson("/api/loans/{$loan['id']}/installments/{$firstInstallment['id']}", [
            'principal_due' => 40000,
            'reason' => 'Invalid reduction below the paid principal.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('principal_due');
    }

    public function test_upcoming_dues_returns_unpaid_installments_in_due_date_order(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $bank = $this->account('Due Bank', 'bank_account');
        $bankMethod = $this->paymentMethod('bank_transfer');
        $lentLoan = $this->postJson('/api/loans', [
            'number' => 'LOAN-DUE-001', 'direction' => 'lent', 'party_name' => 'Juan Borrower',
            'financial_account_id' => $bank->id, 'start_date' => '2026-10-01',
            'first_due_date' => '2026-11-01', 'currency' => 'PHP',
            'principal_amount' => 120000, 'total_interest_amount' => 0, 'term_months' => 12,
        ])->assertCreated()->json();
        $this->postJson("/api/loans/{$lentLoan['id']}/payments", [
            'payment_method_id' => $bankMethod->id,
            'financial_account_id' => $bank->id, 'payment_date' => '2026-10-15', 'amount' => 4000,
        ])->assertCreated();

        $this->postJson('/api/loans', [
            'number' => 'LOAN-DUE-002', 'direction' => 'borrowed', 'party_name' => 'Maria Lender',
            'financial_account_id' => $bank->id, 'start_date' => '2026-10-05',
            'first_due_date' => '2026-11-05', 'currency' => 'PHP',
            'principal_amount' => 2000, 'total_interest_amount' => 0, 'term_months' => 1,
        ])->assertCreated();

        $this->getJson('/api/loans/upcoming-dues?day_from=2026-11-01&day_to=2026-11-30&as_of_date=2026-10-22')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('total_installments', 2)
            ->assertJsonPath('total_amount_due', '8000.0000')
            ->assertJsonPath('overdue_count', 0)
            ->assertJsonPath('overdue_amount', '0.0000')
            ->assertJsonPath('due_within_7_days_count', 0)
            ->assertJsonPath('due_within_7_days_amount', '0.0000')
            ->assertJsonPath('include_overdue', true)
            ->assertJsonPath('data.0.loan_id', $lentLoan['id'])
            ->assertJsonPath('data.0.loan_number', 'LOAN-DUE-001')
            ->assertJsonPath('data.0.party', 'Juan Borrower')
            ->assertJsonPath('data.0.payment_direction', 'inflow')
            ->assertJsonPath('data.0.installment_number', 1)
            ->assertJsonPath('data.0.principal_due', '10000.0000')
            ->assertJsonPath('data.0.interest_due', '0.0000')
            ->assertJsonPath('data.0.paid_amount', '4000.0000')
            ->assertJsonPath('data.0.amount_due', '6000.0000')
            ->assertJsonPath('data.0.urgency', 'Due in 10 days')
            ->assertJsonPath('data.0.loan.id', $lentLoan['id'])
            ->assertJsonPath('data.0.loan.number', 'LOAN-DUE-001')
            ->assertJsonPath('data.0.loan.party', 'Juan Borrower')
            ->assertJsonPath('data.0.loan.principal_amount', '120000.0000')
            ->assertJsonPath('data.0.loan.total_loan_amount', '120000.0000')
            ->assertJsonPath('data.0.loan.balance_amount', '116000.0000')
            ->assertJsonPath('data.0.loan.payment_count', 1)
            ->assertJsonCount(1, 'data.0.payment_entries')
            ->assertJsonPath('data.1.payment_direction', 'outflow')
            ->assertJsonPath('data.1.amount_due', '2000.0000');
    }

    public function test_upcoming_dues_includes_earlier_overdue_installments_and_full_loan_details(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cash = $this->account('Overdue Due Cash', 'cash');
        $loan = $this->postJson('/api/loans', [
            'number' => 'LOAN-OVERDUE-DUE-001',
            'direction' => 'borrowed',
            'party_name' => 'Overdue Lender',
            'financial_account_id' => $cash->id,
            'start_date' => '2026-09-01',
            'first_due_date' => '2026-10-01',
            'currency' => 'PHP',
            'principal_amount' => 100000,
            'total_interest_amount' => 10000,
            'term_months' => 1,
        ])->assertCreated()->json();

        $url = '/api/loan-installments/upcoming-dues?day_from=2026-10-30'
            .'&day_to=2026-11-29&as_of_date=2026-10-30';
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('overdue_count', 1)
            ->assertJsonPath('overdue_amount', '110000.0000')
            ->assertJsonPath('due_within_7_days_count', 0)
            ->assertJsonPath('data.0.loan_id', $loan['id'])
            ->assertJsonPath('data.0.installment_number', 1)
            ->assertJsonPath('data.0.due_date', '2026-10-01')
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.0.principal_due', '100000.0000')
            ->assertJsonPath('data.0.interest_due', '10000.0000')
            ->assertJsonPath('data.0.loan.number', 'LOAN-OVERDUE-DUE-001')
            ->assertJsonPath('data.0.loan.direction', 'borrowed')
            ->assertJsonPath('data.0.loan.party', 'Overdue Lender')
            ->assertJsonPath('data.0.loan.total_loan_amount', '110000.0000')
            ->assertJsonPath('data.0.loan.balance_amount', '110000.0000')
            ->assertJsonPath('data.0.loan.overdue_interest_rate', '0.0000');

        $this->getJson($url.'&include_overdue=0')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('overdue_count', 0)
            ->assertJsonPath('include_overdue', false);
    }

    public function test_upcoming_dues_validates_filters_and_requires_authentication(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/loans/upcoming-dues?day_from=2026-11-02&day_to=2026-11-01&limit=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['day_to', 'limit']);

        auth()->forgetGuards();
        $this->getJson('/api/loans/upcoming-dues')->assertUnauthorized();
    }

    public function test_creation_options_use_internal_bank_and_credit_card_accounts(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $bank = $this->account('Internal BDO', 'bank_account');
        $card = $this->account('Internal Visa', 'credit_card');
        $this->account('Company Cash', 'cash');
        $institution = FinancialInstitution::create([
            'code' => 'BDO', 'name' => 'BDO', 'type' => 'bank', 'is_active' => true,
        ]);
        $holder = UserAccount::create([
            'code' => 'BANK-HOLDER-001', 'type' => 'individual', 'name' => 'Kathleen Mae Pangilinan', 'is_active' => true,
        ]);
        $unlinkedHolder = UserAccount::create([
            'code' => 'CARL-CEDRICK', 'type' => 'individual', 'name' => 'CARL CEDRICK P. CHUA', 'is_active' => true,
        ]);
        $bank->update([
            'financial_institution_id' => $institution->id,
            'account_holder' => $holder->id,
        ]);

        $this->getJson('/api/loans/creation-options?currency=php')
            ->assertOk()
            ->assertJsonPath('currency', 'PHP')
            ->assertJsonPath('financial_institutions.0.id', $institution->id)
            ->assertJsonPath('financial_institutions.0.compatible_accounts_count', 1)
            ->assertJsonPath('borrower_types.0.value', 'company')
            ->assertJsonPath('borrower_types.0.label', 'External person / company')
            ->assertJsonPath('borrower_types.0.internal_only', false)
            ->assertJsonPath('borrower_types.0.requires_financial_account', false)
            ->assertJsonCount(0, 'borrower_types.0.accounts')
            ->assertJsonPath('borrower_types.1.value', 'bank')
            ->assertJsonPath('borrower_types.1.internal_only', true)
            ->assertJsonPath('borrower_types.1.requires_financial_institution', true)
            ->assertJsonPath('borrower_types.1.requires_user_account', true)
            ->assertJsonPath('borrower_types.1.requires_financial_account', false)
            ->assertJsonCount(0, 'borrower_types.1.accounts')
            ->assertJsonFragment(['id' => $unlinkedHolder->id, 'name' => 'CARL CEDRICK P. CHUA'])
            ->assertJsonPath('borrower_types.2.value', 'credit_card')
            ->assertJsonPath('borrower_types.2.internal_only', true)
            ->assertJsonPath('borrower_types.2.requires_financial_account', true)
            ->assertJsonPath('borrower_types.2.accounts.0.id', $card->id);

        $payload = [
            'borrower_type' => 'bank',
            'first_due_date' => '2026-11-22', 'currency' => 'PHP',
            'principal_amount' => 100000, 'total_interest' => 10000, 'term_months' => 10,
        ];
        $this->postJson('/api/loans', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['financial_institution_id', 'user_account_id']);

        $payload['financial_institution_id'] = $institution->id;
        $payload['user_account_id'] = $unlinkedHolder->id;
        $this->postJson('/api/loans', $payload)
            ->assertCreated()
            ->assertJsonPath('borrower_type', 'bank')
            ->assertJsonPath('direction', 'borrowed')
            ->assertJsonPath('total_interest_amount', '10000.0000')
            ->assertJsonPath('financial_institution.id', $institution->id)
            ->assertJsonPath('user_account.id', $unlinkedHolder->id)
            ->assertJsonPath('financial_account_id', null)
            ->assertJsonPath('borrower_name', 'BDO');
    }

    public function test_loan_payment_options_only_return_supported_methods_and_compatible_accounts(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cashMethod = $this->paymentMethod('cash');
        $bankMethod = $this->paymentMethod('bank_transfer');
        $chequeMethod = $this->paymentMethod('cheque', true);
        $walletMethod = $this->paymentMethod('e_wallet');
        $this->paymentMethod('credit_card', true);
        $this->paymentMethod('other');

        $institution = FinancialInstitution::create([
            'code' => 'BDO-PAY', 'name' => 'BDO Payment Bank', 'type' => 'bank', 'is_active' => true,
        ]);
        $bank = $this->account('Payment Bank Account', 'bank_account');
        $checking = $this->account('Payment Checking Account', 'checking_account');
        $wallet = $this->account('Payment Wallet', 'e_wallet');
        $bank->update(['financial_institution_id' => $institution->id]);
        $checking->update(['financial_institution_id' => $institution->id]);
        $loan = $this->postJson('/api/loans', [
            'direction' => 'borrowed', 'borrower_type' => 'company', 'party_name' => 'External Lender',
            'start_date' => '2026-10-27', 'first_due_date' => '2026-11-27', 'currency' => 'PHP',
            'principal_amount' => 10000, 'total_interest_amount' => 0, 'term_months' => 1,
        ])->assertCreated()->json();

        $this->getJson("/api/loans/{$loan['id']}/payment-methods")
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonFragment(['id' => $cashMethod->id, 'type' => 'cash'])
            ->assertJsonFragment(['id' => $bankMethod->id, 'type' => 'bank_transfer'])
            ->assertJsonFragment(['id' => $chequeMethod->id, 'type' => 'cheque'])
            ->assertJsonFragment(['id' => $walletMethod->id, 'type' => 'e_wallet'])
            ->assertJsonMissing(['type' => 'credit_card'])
            ->assertJsonMissing(['type' => 'other']);

        $this->getJson("/api/loans/{$loan['id']}/payment-accounts?payment_method_id={$bankMethod->id}")
            ->assertOk()
            ->assertJsonPath('financial_institutions.0.id', $institution->id)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $bank->id, 'kind' => 'bank_account'])
            ->assertJsonFragment(['id' => $checking->id, 'kind' => 'checking_account'])
            ->assertJsonMissing(['id' => $wallet->id]);

        $this->getJson("/api/loans/{$loan['id']}/payment-accounts?payment_method_id={$chequeMethod->id}&financial_institution_id={$institution->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $checking->id);

        $this->postJson("/api/loans/{$loan['id']}/payments", [
            'payment_method_id' => $walletMethod->id,
            'financial_account_id' => $bank->id,
            'payment_date' => '2026-10-27',
            'amount' => 1000,
        ])->assertUnprocessable()->assertJsonValidationErrors('financial_account_id');
    }

    public function test_extra_payment_can_be_overdue_interest_expense_or_next_installment_overpayment(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cash = $this->account('Excess Treatment Cash', 'cash');
        $cashMethod = $this->paymentMethod('cash');
        $loanPayload = [
            'direction' => 'borrowed',
            'borrower_type' => 'company',
            'party_name' => 'Private Lender',
            'financial_account_id' => $cash->id,
            'start_date' => '2025-12-01',
            'first_due_date' => '2026-01-01',
            'currency' => 'PHP',
            'principal_amount' => 200000,
            'total_interest_amount' => 0,
            'term_months' => 2,
            'overdue_interest_rate' => 5,
            'overdue_interest_period' => 'monthly',
        ];

        $interestLoan = $this->postJson('/api/loans', $loanPayload)->assertCreated()->json();
        $this->postJson("/api/loans/{$interestLoan['id']}/payments", [
            'payment_method_id' => $cashMethod->id,
            'financial_account_id' => $cash->id,
            'excess_treatment' => 'overdue_interest',
            'payment_date' => '2026-01-31',
            'amount' => 105000,
        ])->assertCreated()
            ->assertJsonPath('overdue_interest_amount', '5000.0000')
            ->assertJsonPath('overdue_interest_classification', 'expense')
            ->assertJsonPath('overpayment_amount', '0.0000')
            ->assertJsonPath('allocations.0.overdue_interest_amount', '5000.0000')
            ->assertJsonPath('allocations.0.overdue_interest_waived_amount', '0.0000');

        $overpaymentLoan = $this->postJson('/api/loans', $loanPayload)->assertCreated()->json();
        $this->postJson("/api/loans/{$overpaymentLoan['id']}/payments", [
            'payment_method_id' => $cashMethod->id,
            'financial_account_id' => $cash->id,
            'excess_treatment' => 'overpayment',
            'payment_date' => '2026-01-31',
            'amount' => 105000,
        ])->assertCreated()
            ->assertJsonPath('overdue_interest_amount', '0.0000')
            ->assertJsonPath('overdue_interest_classification', null)
            ->assertJsonPath('overpayment_amount', '5000.0000')
            ->assertJsonPath('allocations.0.overdue_interest_waived_amount', '5000.0000')
            ->assertJsonPath('allocations.1.principal_amount', '5000.0000');

        $this->getJson("/api/loans/{$overpaymentLoan['id']}?as_of_date=2026-01-31")
            ->assertOk()
            ->assertJsonPath('installments.0.total_due', '0.0000')
            ->assertJsonPath('installments.0.paid_amount', '105000.0000')
            ->assertJsonPath('installments.0.payment_received_amount', '105000.0000')
            ->assertJsonPath('installments.0.scheduled_paid_amount', '100000.0000')
            ->assertJsonPath('installments.0.overpayment_applied_amount', '0.0000')
            ->assertJsonPath('installments.1.paid_amount', '5000.0000')
            ->assertJsonPath('installments.1.payment_received_amount', '0.0000')
            ->assertJsonPath('installments.1.scheduled_paid_amount', '5000.0000')
            ->assertJsonPath('installments.1.overpayment_applied_amount', '5000.0000')
            ->assertJsonPath('installments.1.outstanding_amount', '95000.0000');

        $manualInterestPayload = array_merge($loanPayload, [
            'overdue_interest_rate' => 0,
        ]);
        $manualInterestLoan = $this->postJson('/api/loans', $manualInterestPayload)
            ->assertCreated()
            ->json();

        $this->postJson("/api/loans/{$manualInterestLoan['id']}/payments", [
            'payment_method_id' => $cashMethod->id,
            'financial_account_id' => $cash->id,
            'excess_treatment' => 'overdue_interest',
            'payment_date' => '2026-01-01',
            'amount' => 120000,
        ])->assertCreated()
            ->assertJsonPath('overdue_interest_amount', '20000.0000')
            ->assertJsonPath('overdue_interest_classification', 'expense')
            ->assertJsonPath('overpayment_amount', '0.0000')
            ->assertJsonCount(1, 'allocations')
            ->assertJsonPath('allocations.0.overdue_interest_amount', '20000.0000');

        $this->getJson("/api/loans/{$manualInterestLoan['id']}?as_of_date=2026-01-01")
            ->assertOk()
            ->assertJsonPath('overpayment_amount', '0.0000')
            ->assertJsonPath('overdue_interest_paid_amount', '20000.0000')
            ->assertJsonPath('total_interest_paid_amount', '20000.0000')
            ->assertJsonPath('installments.0.payment_received_amount', '120000.0000')
            ->assertJsonPath('installments.0.scheduled_paid_amount', '100000.0000')
            ->assertJsonPath('installments.0.overpayment_amount', '0.0000')
            ->assertJsonPath('installments.0.overdue_interest_paid_amount', '20000.0000')
            ->assertJsonPath('installments.0.total_interest_paid_amount', '20000.0000')
            ->assertJsonPath('installments.1.payment_received_amount', '0.0000')
            ->assertJsonPath('installments.1.scheduled_paid_amount', '0.0000')
            ->assertJsonPath('installments.1.overpayment_applied_amount', '0.0000')
            ->assertJsonPath('installments.1.outstanding_amount', '100000.0000');

        $manualInterestList = $this->getJson('/api/loans/list?as_of_date=2026-01-01')
            ->assertOk()
            ->json('data');
        $manualInterestSummary = collect($manualInterestList)->firstWhere('id', $manualInterestLoan['id']);
        $this->assertSame('0.0000', $manualInterestSummary['overpayment_amount']);
        $this->assertSame('20000.0000', $manualInterestSummary['overdue_interest_paid_amount']);
        $this->assertSame('20000.0000', $manualInterestSummary['total_interest_paid_amount']);
        $this->assertSame('20000.0000', $manualInterestSummary['interest_expense_amount']);
        $this->assertSame('20000.0000', $manualInterestSummary['overdue_interest_expense_amount']);

        $overpaymentList = $this->getJson('/api/loans/list?as_of_date=2026-01-31')
            ->assertOk()
            ->json('data');
        $overpaymentSummary = collect($overpaymentList)->firstWhere('id', $overpaymentLoan['id']);
        $this->assertSame('5000.0000', $overpaymentSummary['overpayment_amount']);
        $this->assertSame('0.0000', $overpaymentSummary['overdue_interest_paid_amount']);
    }

    private function account(string $name, string $kind): FinancialAccount
    {
        $ledger = ChartAccount::create([
            'code' => 'LEDGER-'.strtoupper(str_replace(' ', '-', $name)), 'name' => $name, 'type' => $kind === 'credit_card' ? 'liability' : 'asset',
        ]);

        return FinancialAccount::create([
            'chart_account_id' => $ledger->id, 'kind' => $kind, 'name' => $name,
            'currency' => 'PHP', 'is_company_wide' => true, 'is_active' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
            'credit_limit' => $kind === 'credit_card' ? 500000 : null,
        ]);
    }

    private function paymentMethod(string $type, bool $createsObligation = false): PaymentMethod
    {
        return PaymentMethod::create([
            'code' => strtoupper($type),
            'name' => ucwords(str_replace('_', ' ', $type)),
            'type' => $type,
            'creates_obligation' => $createsObligation,
            'is_active' => true,
        ]);
    }
}
