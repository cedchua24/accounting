<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegratedCustomerOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_list_reads_online_orders_and_only_returns_account_overpayments(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cashLedger = ChartAccount::create(['code' => '1001', 'name' => 'Central Cash', 'type' => 'asset']);
        $account = FinancialAccount::create([
            'name' => 'Cash Store', 'kind' => 'cash', 'currency' => 'PHP',
            'chart_account_id' => $cashLedger->id, 'is_company_wide' => true,
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

        Config::set('database.connections.legacy_mysql', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('legacy_mysql');
        $legacySchema = Schema::connection('legacy_mysql');
        $legacySchema->create('shop', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('shop_type_id');
        });
        $legacySchema->create('shop_order_transaction', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('shop_id');
            $table->decimal('shop_order_transaction_total_price', 19, 4);
            $table->date('date');
        });
        DB::connection('legacy_mysql')->table('shop')->insert([
            ['id' => 1, 'shop_type_id' => 3],
            ['id' => 2, 'shop_type_id' => 1],
        ]);
        DB::connection('legacy_mysql')->table('shop_order_transaction')->insert([
            [
                'id' => 900, 'shop_id' => 1,
                'shop_order_transaction_total_price' => 400,
                'date' => '2026-10-04',
            ],
            [
                'id' => 901, 'shop_id' => 2,
                'shop_order_transaction_total_price' => 100,
                'date' => '2026-10-04',
            ],
        ]);

        try {
            $this->postJson('/api/shopOrderTransaction/fetchOnlineShopOrderRefundTransactionListV2', [
                'date_from' => '2026-10-01',
                'date_to' => '2026-10-31',
            ])->assertOk()
                ->assertJsonPath('total_count', 1)
                ->assertJsonPath('data.0.transaction_id', 900)
                ->assertJsonPath('data.0.total_amount', '400.0000')
                ->assertJsonPath('data.0.total_account', '500.0000')
                ->assertJsonPath('data.0.refund_amount', '100.0000');
        } finally {
            DB::purge('legacy_mysql');
        }
    }

    public function test_branch_shop_order_payment_is_upserted_and_posted_centrally(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cashLedger = ChartAccount::create(['code' => '1001', 'name' => 'Central Cash', 'type' => 'asset']);
        $account = FinancialAccount::create([
            'name' => 'Cash Store', 'kind' => 'cash', 'currency' => 'PHP',
            'chart_account_id' => $cashLedger->id, 'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $account->applications()->create([
            'application' => FinancialAccountApplication::CUSTOMER_RECEIPT,
            'source' => 'manual',
        ]);
        $method = PaymentMethod::create(['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash']);
        $key = (string) Str::uuid();
        $payload = [
            'source' => 'bakingstore-branch-1',
            'external_branch_id' => 2, 'branch_name' => 'Quezon City',
            'external_customer_id' => 15, 'customer_name' => 'Juan Customer',
            'external_order_id' => 900, 'order_date' => '2026-10-04', 'order_total' => 950,
            'financial_account_id' => $account->id, 'payment_method_id' => $method->id,
            'payment_date' => '2026-10-04', 'amount' => 500, 'currency' => 'PHP',
            'reference' => 'OR-900', 'idempotency_key' => $key,
        ];

        $response = $this->postJson('/api/integrations/customer-order/payments', $payload)
            ->assertCreated()
            ->assertJsonPath('total_payment', '500.0000')
            ->assertJsonPath('balance', '450.0000')
            ->assertJsonPath('data.0.status', 'posted');
        $paymentId = $response->json('data.0.id');

        $this->postJson('/api/integrations/customer-order/payments', $payload)
            ->assertCreated()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('branches', ['legacy_source' => 'bakingstore-branch-1.shop', 'legacy_id' => 2]);
        $this->assertDatabaseHas('counterparties', ['legacy_source' => 'bakingstore-branch-1.customer', 'legacy_id' => 15]);

        $this->getJson('/api/integrations/customer-order?source=bakingstore-branch-1&external_order_id=900')
            ->assertOk()->assertJsonPath('balance', '450.0000');
        $this->postJson('/api/integrations/customer-orders/summaries', [
            'source' => 'bakingstore-branch-1',
            'external_order_ids' => [900, 901],
        ])->assertOk()
            ->assertJsonPath('data.900.total_payment', '500.0000')
            ->assertJsonPath('data.900.invoice.legacy_id', 900)
            ->assertJsonMissingPath('data.901');

        $refundCandidatePayload = [
            'source' => 'bakingstore-branch-1',
            'orders' => [
                ['external_order_id' => 900, 'order_date' => '2026-10-04', 'order_total' => 400],
                ['external_order_id' => 901, 'order_date' => '2026-10-04', 'order_total' => 100],
            ],
        ];

        $this->postJson('/api/integrations/customer-orders/refund-candidates', $refundCandidatePayload)
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('total_count', 1)
            ->assertJsonPath('data.0.transaction_id', 900)
            ->assertJsonPath('data.0.date', '2026-10-04')
            ->assertJsonPath('data.0.total_amount', '400.0000')
            ->assertJsonPath('data.0.total_account', '500.0000')
            ->assertJsonPath('data.0.refund_amount', '100.0000')
            ->assertJsonPath('data.0.total_refunded', '0.0000')
            ->assertJsonPath('data.0.original_payment_id', $paymentId);

        $this->postJson('/api/shopOrderTransaction/fetchOnlineShopOrderRefundTransactionListV2', $refundCandidatePayload)
            ->assertOk()
            ->assertJsonPath('total_count', 1)
            ->assertJsonPath('data.0.transaction_id', 900)
            ->assertJsonPath('data.0.refund_amount', '100.0000');

        $this->postJson('/api/shopOrderTransaction/fetchOnlineShopOrderRefundTransactionListV2', [
            'source' => 'bakingstore-branch-1',
            'orders' => [
                ['external_order_id' => 900, 'order_date' => '2026-10-04', 'order_total' => 500],
            ],
        ])->assertOk()
            ->assertJsonPath('total_count', 0)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/integrations/customer-payment-report?source=bakingstore-branch-1&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('total_cash', '500.0000')
            ->assertJsonPath('total_online', '0.0000')
            ->assertJsonPath('total_sales', '500.0000')
            ->assertJsonPath('data.0.date', '2026-10-04')
            ->assertJsonPath('data.0.total_count', 1)
            ->assertJsonPath('data.0.customers.0.external_customer_id', 15)
            ->assertJsonPath('data.0.customers.0.total_sales', '500.0000')
            ->assertJsonPath('data.0.orders.0.external_order_id', 900)
            ->assertJsonPath('data.0.orders.0.total_sales', '500.0000')
            ->assertJsonPath('data.0.accounts.0.account_name', 'Cash Store');

        $reconciliation = $this->getJson('/api/integrations/customer-payment-reconciliation?source=bakingstore-branch-1&financial_account_id='.$account->id.'&order_date=2026-10-04')
            ->assertOk()
            ->assertJsonPath('total_count', 1)
            ->assertJsonPath('confirmed_count', 0)
            ->assertJsonPath('data.0.external_order_id', 900);
        $lineId = $reconciliation->json('data.0.id');

        $this->putJson("/api/integrations/customer-payment-lines/{$lineId}/confirmation", ['confirmed' => true])
            ->assertOk()
            ->assertJsonPath('confirmed_by.id', auth()->id());
        $this->assertDatabaseMissing('payment_lines', ['id' => $lineId, 'confirmed_at' => null]);

        $this->getJson('/api/integrations/customer-payment-reconciliation?source=bakingstore-branch-1&financial_account_id='.$account->id.'&order_date=2026-10-04')
            ->assertOk()
            ->assertJsonPath('confirmed_count', 1)
            ->assertJsonPath('confirmed_amount', '500.0000');
        $this->postJson("/api/payments/{$paymentId}/void", ['reason' => 'Cash entry correction'])
            ->assertOk();
        $this->getJson('/api/integrations/customer-order?source=bakingstore-branch-1&external_order_id=900')
            ->assertOk()->assertJsonPath('total_payment', '0.0000')->assertJsonPath('balance', '950.0000');
    }
}
