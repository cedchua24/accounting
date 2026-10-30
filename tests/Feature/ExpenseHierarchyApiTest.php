<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\ChartAccountGroup;
use App\Models\ExpenseItem;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseHierarchyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_chart_groups_and_financial_expense_setup_are_frontend_ready(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/chart-account-groups/list')
            ->assertOk()
            ->assertJsonCount(6)
            ->assertJsonFragment([
                'code' => 'COGS',
                'chart_of_account_name' => 'Cost of Goods Sold',
                'normal_balance' => 'debit',
                'status' => 0,
            ]);

        $this->getJson('/api/expense-setup')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'FINANCIAL_COSTS')
            ->assertJsonPath('data.0.expense_type', 'Bank and Financing Costs')
            ->assertJsonFragment([
                'system_key' => 'CREDIT_CARD_INTEREST',
                'expense_name' => 'Credit Card Interest',
            ])
            ->assertJsonFragment([
                'system_key' => 'LOAN_OVERDUE_INTEREST',
                'expense_name' => 'Loan Overdue Interest',
            ]);

        $this->getJson('/api/expense-items/list?active=1&hidden=0')
            ->assertOk()
            ->assertJsonCount(9)
            ->assertJsonFragment(['expense_code' => 'BANK_SERVICE_CHARGE']);
    }

    public function test_manual_expense_uses_selected_item_and_requires_approval_before_payment(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $branch = $this->branch('EXP');
        $item = ExpenseItem::where('system_key', 'BANK_SERVICE_CHARGE')->firstOrFail();
        $payload = [
            'branch_id' => $branch->id,
            'number' => 'EXP-MANUAL-001',
            'expense_item_id' => $item->id,
            'expense_date' => '2026-10-28',
            'currency' => 'PHP',
            'amount' => 750,
            'approval_status' => 'pending',
            'description' => 'Monthly bank service charge',
            'reference' => 'BANK-OCT-2026',
        ];

        $expense = $this->postJson('/api/expenses', $payload)
            ->assertCreated()
            ->assertJsonPath('chart_account_id', $item->chart_account_id)
            ->assertJsonPath('expense_code', 'BANK_SERVICE_CHARGE')
            ->assertJsonPath('expense_name', 'Bank Service Charge')
            ->assertJsonPath('expense_type.code', 'FINANCIAL_COSTS')
            ->assertJsonPath('approval_status', 'pending')
            ->assertJsonPath('created_by', $user->id)
            ->json();

        [$funding, $method] = $this->fundingAccount();
        $payment = [
            'branch_id' => $branch->id,
            'type' => 'expense',
            'expense_id' => $expense['id'],
            'offset_account_id' => $item->chart_account_id,
            'payment_date' => '2026-10-28',
            'currency' => 'PHP',
            'lines' => [[
                'financial_account_id' => $funding->id,
                'payment_method_id' => $method->id,
                'amount' => 750,
            ]],
            'post' => true,
        ];
        $this->postJson('/api/payments', $payment)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expense_id');

        $this->postJson("/api/expenses/{$expense['id']}/reject", ['notes' => 'Need bank statement'])
            ->assertOk()
            ->assertJsonPath('approval_status', 'rejected');

        $payload['approval_status'] = 'pending';
        $payload['description'] = 'Monthly bank service charge - statement attached';
        $this->putJson("/api/expenses/{$expense['id']}", $payload)
            ->assertOk()
            ->assertJsonPath('approval_status', 'pending');
        $this->postJson("/api/expenses/{$expense['id']}/approve", ['notes' => 'Statement checked'])
            ->assertOk()
            ->assertJsonPath('approval_status', 'approved')
            ->assertJsonPath('approved_by', $user->id);

        $this->postJson('/api/payments', $payment)
            ->assertCreated()
            ->assertJsonPath('status', 'posted');
    }

    public function test_expense_ledger_includes_loan_interest_once_as_read_only_system_rows(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$cash, $method] = $this->fundingAccount('cash');
        $loan = $this->postJson('/api/loans', [
            'direction' => 'borrowed',
            'borrower_type' => 'company',
            'party_name' => 'Private Lender',
            'start_date' => '2025-12-01',
            'first_due_date' => '2026-01-01',
            'currency' => 'PHP',
            'principal_amount' => 100000,
            'total_interest_amount' => 20000,
            'term_months' => 1,
            'overdue_interest_rate' => 5,
            'overdue_interest_period' => 'monthly',
        ])->assertCreated()->json();

        $this->postJson("/api/loans/{$loan['id']}/payments", [
            'payment_method_id' => $method->id,
            'financial_account_id' => $cash->id,
            'excess_treatment' => 'overdue_interest',
            'payment_date' => '2026-01-31',
            'amount' => 126000,
        ])->assertCreated()
            ->assertJsonPath('overdue_interest_amount', '6000.0000');

        $response = $this->getJson('/api/expenses/ledger?source_type=loan_interest&date_from=2026-01-01&date_to=2026-01-31')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.system_generated', true)
            ->assertJsonPath('data.0.read_only', true)
            ->assertJsonPath('data.0.payment_status', 'posted')
            ->assertJsonFragment([
                'expense_code' => 'LOAN_INTEREST',
                'amount' => '20000.0000',
                'source_type' => 'loan_interest',
            ])
            ->assertJsonFragment([
                'expense_code' => 'LOAN_OVERDUE_INTEREST',
                'amount' => '6000.0000',
                'source_type' => 'loan_interest',
            ]);

        $this->assertCount(2, collect($response->json('data'))->pluck('register_id')->unique());
    }

    public function test_chart_account_rejects_parent_cycles_and_deletion_with_children(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $group = ChartAccountGroup::where('code', 'EXPENSE')->firstOrFail();
        $parent = $this->postJson('/api/chart-accounts', [
            'group_id' => $group->id,
            'code' => 'TEST-EXP-GROUP',
            'name' => 'Test Expense Group',
            'type' => 'expense',
            'is_postable' => false,
        ])->assertCreated()->json();
        $child = $this->postJson('/api/chart-accounts', [
            'group_id' => $group->id,
            'code' => 'TEST-EXP-CHILD',
            'name' => 'Test Expense Child',
            'type' => 'expense',
            'parent_id' => $parent['id'],
            'is_postable' => true,
        ])->assertCreated()->json();

        $this->putJson("/api/chart-accounts/{$parent['id']}", [
            'group_id' => $group->id,
            'code' => 'TEST-EXP-GROUP',
            'name' => 'Test Expense Group',
            'type' => 'expense',
            'parent_id' => $child['id'],
            'is_postable' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->deleteJson("/api/chart-accounts/{$parent['id']}")
            ->assertStatus(409);
    }

    private function fundingAccount(string $kind = 'bank_account'): array
    {
        $ledger = ChartAccount::create([
            'code' => 'FUND-'.strtoupper($kind).'-'.ChartAccount::count(),
            'name' => 'Funding '.ucwords(str_replace('_', ' ', $kind)),
            'type' => 'asset',
        ]);
        $account = FinancialAccount::create([
            'chart_account_id' => $ledger->id,
            'kind' => $kind,
            'name' => 'Funding Account '.$ledger->id,
            'currency' => 'PHP',
            'is_company_wide' => true,
            'usable_for_receipts' => true,
            'usable_for_payments' => true,
        ]);
        $methodType = $kind === 'cash' ? 'cash' : 'bank_transfer';
        $method = PaymentMethod::firstOrCreate(
            ['code' => 'METHOD-'.strtoupper($methodType)],
            ['name' => 'Method '.ucwords(str_replace('_', ' ', $methodType)), 'type' => $methodType, 'is_active' => true]
        );

        return [$account, $method];
    }

    private function branch(string $prefix): Branch
    {
        $inventory = ChartAccount::create(['code' => $prefix.'-INV', 'name' => $prefix.' Inventory', 'type' => 'asset']);
        $dueFrom = ChartAccount::create(['code' => $prefix.'-DF', 'name' => $prefix.' Due From', 'type' => 'asset']);
        $dueTo = ChartAccount::create(['code' => $prefix.'-DT', 'name' => $prefix.' Due To', 'type' => 'liability']);

        return Branch::create([
            'code' => $prefix,
            'name' => $prefix.' Branch',
            'inventory_account_id' => $inventory->id,
            'due_from_account_id' => $dueFrom->id,
            'due_to_account_id' => $dueTo->id,
        ]);
    }
}
