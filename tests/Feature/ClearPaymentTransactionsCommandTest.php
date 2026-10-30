<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClearPaymentTransactionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dry_runs_then_removes_only_payment_transactions_and_preserves_accounts(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $ledger = ChartAccount::create(['code' => '1001', 'name' => 'Central Cash', 'type' => 'asset']);
        $account = FinancialAccount::create([
            'name' => 'Cash Store', 'kind' => 'cash', 'currency' => 'PHP',
            'chart_account_id' => $ledger->id, 'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $account->applications()->create([
            'application' => FinancialAccountApplication::CUSTOMER_RECEIPT,
            'source' => 'manual',
        ]);
        $method = PaymentMethod::create(['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash']);

        $this->postJson('/api/integrations/customer-order/payments', [
            'source' => 'bakingstore-branch-1',
            'external_branch_id' => 2, 'branch_name' => 'Quezon City',
            'external_customer_id' => 15, 'customer_name' => 'Juan Customer',
            'external_order_id' => 900, 'order_date' => '2026-10-04', 'order_total' => 950,
            'financial_account_id' => $account->id, 'payment_method_id' => $method->id,
            'payment_date' => '2026-10-04', 'amount' => 500, 'currency' => 'PHP',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        Artisan::call('accounting:clear-payment-transactions', ['--source' => 'bakingstore-branch-1']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('financial_accounts', 1);

        Artisan::call('accounting:clear-payment-transactions', [
            '--source' => 'bakingstore-branch-1',
            '--confirm' => true,
        ]);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_lines', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('financial_accounts', 1);
        $this->assertDatabaseHas('sales_invoices', [
            'legacy_source' => 'bakingstore-branch-1.shop_order_transaction',
            'legacy_id' => 900,
            'status' => 'unpaid',
        ]);
    }
}
