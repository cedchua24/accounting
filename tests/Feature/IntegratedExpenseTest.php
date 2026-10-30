<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegratedExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_expense_and_its_payment_are_recorded_centrally(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cashLedger = ChartAccount::create(['code' => '1003', 'name' => 'Expense Cash', 'type' => 'asset']);
        $account = FinancialAccount::create([
            'name' => 'Expense Cash Account', 'kind' => 'cash', 'currency' => 'PHP',
            'chart_account_id' => $cashLedger->id, 'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $account->applications()->create([
            'application' => FinancialAccountApplication::EXPENSE_PAYMENT,
            'source' => 'manual',
        ]);
        $method = PaymentMethod::create(['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash']);

        $response = $this->postJson('/api/integrations/expenses', [
            'source' => 'bakingstore-branch-1',
            'external_branch_id' => 5,
            'branch_name' => 'MDR Commonwealth',
            'external_expense_id' => 812,
            'external_chart_account_id' => 7,
            'chart_account_name' => 'Vehicle expense',
            'expense_name' => 'Fortuner fuel',
            'expense_date' => '2026-10-10',
            'amount' => 2500,
            'description' => 'Fuel expense',
            'currency' => 'PHP',
            'record_payment' => true,
            'financial_account_id' => $account->id,
            'payment_method_id' => $method->id,
            'payment_date' => '2026-10-10',
            'installment_count' => 1,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()
            ->assertJsonPath('expense.legacy_id', 812)
            ->assertJsonPath('expense.status', 'paid')
            ->assertJsonPath('expense.chart_account.legacy_id', 7)
            ->assertJsonPath('total_payment', '2500.0000')
            ->assertJsonPath('balance', '0.0000')
            ->assertJsonPath('data.0.status', 'posted');

        $this->assertDatabaseHas('payments', [
            'id' => $response->json('data.0.id'),
            'type' => 'expense',
            'direction' => 'outflow',
            'status' => 'posted',
        ]);
        $this->assertDatabaseHas('chart_accounts', [
            'legacy_source' => 'bakingstore-branch-1.chart_of_accounts',
            'legacy_id' => 7,
            'type' => 'expense',
        ]);

        $this->postJson('/api/integrations/expenses/summaries', [
            'source' => 'bakingstore-branch-1',
            'external_expense_ids' => [812, 9999],
        ])->assertOk()
            ->assertJsonPath('data.812.expense.status', 'paid')
            ->assertJsonPath('data.812.total_payment', '2500.0000')
            ->assertJsonMissingPath('data.9999');

        $this->postJson('/api/integrations/expenses/cancel', [
            'source' => 'bakingstore-branch-1',
            'external_expense_id' => 812,
            'reason' => 'Deleted from the branch expense register',
        ])->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('cancelled', true)
            ->assertJsonPath('expense.status', 'cancelled')
            ->assertJsonPath('data.0.status', 'voided')
            ->assertJsonPath('total_payment', '0.0000');

        $this->assertDatabaseHas('payments', [
            'id' => $response->json('data.0.id'),
            'status' => 'voided',
            'void_reason' => 'Deleted from the branch expense register',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'payment_id' => $response->json('data.0.id'),
            'kind' => 'reversal',
        ]);

        // Retrying after a partial network failure is safe and creates no second reversal.
        $this->postJson('/api/integrations/expenses/cancel', [
            'source' => 'bakingstore-branch-1',
            'external_expense_id' => 812,
            'reason' => 'Deleted from the branch expense register',
        ])->assertOk()->assertJsonPath('expense.status', 'cancelled');
        $this->assertDatabaseCount('journal_entries', 2);

        // Expenses created before Central Accounting integration do not block local deletion.
        $this->postJson('/api/integrations/expenses/cancel', [
            'source' => 'bakingstore-branch-1',
            'external_expense_id' => 9999,
            'reason' => 'Deleted from the branch expense register',
        ])->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonPath('cancelled', false);
    }
}
