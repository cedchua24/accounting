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

class IntegratedCustomerOrderTest extends TestCase
{
    use RefreshDatabase;

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
        $this->postJson("/api/payments/{$paymentId}/void", ['reason' => 'Cash entry correction'])
            ->assertOk();
        $this->getJson('/api/integrations/customer-order?source=bakingstore-branch-1&external_order_id=900')
            ->assertOk()->assertJsonPath('total_payment', '0.0000')->assertJsonPath('balance', '950.0000');
    }
}
