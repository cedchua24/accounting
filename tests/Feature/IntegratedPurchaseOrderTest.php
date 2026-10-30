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

class IntegratedPurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_supplier_payment_is_upserted_and_posted_centrally(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cashLedger = ChartAccount::create(['code' => '1002', 'name' => 'Supplier Cash', 'type' => 'asset']);
        $account = FinancialAccount::create([
            'name' => 'Supplier Cash Account', 'kind' => 'cash', 'currency' => 'PHP',
            'chart_account_id' => $cashLedger->id, 'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $account->applications()->create([
            'application' => FinancialAccountApplication::PURCHASE_ORDER_PAYMENT,
            'source' => 'manual',
        ]);
        $method = PaymentMethod::create(['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash']);
        $payload = [
            'source' => 'bakingstore-branch-1',
            'external_branch_id' => 2, 'branch_name' => 'Quezon City',
            'external_supplier_id' => 18, 'supplier_name' => 'AMAX',
            'external_order_id' => 2813, 'order_date' => '2026-10-10', 'order_total' => 3750,
            'financial_account_id' => $account->id, 'payment_method_id' => $method->id,
            'payment_date' => '2026-10-10', 'amount' => 1000, 'currency' => 'PHP',
            'installment_count' => 1, 'idempotency_key' => (string) Str::uuid(),
        ];

        $response = $this->postJson('/api/integrations/purchase-order/payments', $payload)
            ->assertCreated()
            ->assertJsonPath('total_payment', '1000.0000')
            ->assertJsonPath('balance', '2750.0000')
            ->assertJsonPath('purchase_order.legacy_id', 2813)
            ->assertJsonPath('data.0.status', 'posted');
        $paymentId = $response->json('data.0.id');

        $this->getJson('/api/integrations/purchase-order?source=bakingstore-branch-1&external_order_id=2813')
            ->assertOk()
            ->assertJsonPath('purchase_order.supplier.name', 'AMAX')
            ->assertJsonPath('balance', '2750.0000');
        $this->postJson('/api/integrations/purchase-orders/summaries', [
            'source' => 'bakingstore-branch-1',
            'external_order_ids' => [2813, 9999],
        ])->assertOk()
            ->assertJsonPath('data.2813.total_payment', '1000.0000')
            ->assertJsonPath('data.2813.balance', '2750.0000')
            ->assertJsonMissingPath('data.9999');
        $this->assertDatabaseHas('purchase_orders', [
            'legacy_source' => 'bakingstore-branch-1.order_supplier_transaction',
            'legacy_id' => 2813,
            'status' => 'partially_paid',
        ]);

        $this->postJson("/api/payments/{$paymentId}/void", ['reason' => 'Supplier payment correction'])
            ->assertOk();
        $this->getJson('/api/integrations/purchase-order?source=bakingstore-branch-1&external_order_id=2813')
            ->assertOk()
            ->assertJsonPath('total_payment', '0.0000')
            ->assertJsonPath('balance', '3750.0000');
    }
}
