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

class CustomerRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_and_full_item_return_refunds_reduce_the_net_sale_and_cash(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cashLedger = ChartAccount::create(['code' => '1001', 'name' => 'GCash', 'type' => 'asset']);
        $account = FinancialAccount::create([
            'name' => 'GCash Ced', 'kind' => 'e_wallet', 'currency' => 'PHP',
            'chart_account_id' => $cashLedger->id, 'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $account->applications()->create([
            'application' => FinancialAccountApplication::CUSTOMER_RECEIPT,
            'source' => 'manual',
        ]);
        $method = PaymentMethod::create(['code' => 'EWALLET', 'name' => 'E-Wallet', 'type' => 'e_wallet']);

        $paymentResponse = $this->postJson('/api/integrations/customer-order/payments', [
            'source' => 'bakingstore-branch-1',
            'external_branch_id' => 2, 'branch_name' => 'Quezon City',
            'external_customer_id' => 15, 'customer_name' => 'Juan Customer',
            'external_order_id' => 901, 'order_date' => '2026-10-10', 'order_total' => 1000,
            'financial_account_id' => $account->id, 'payment_method_id' => $method->id,
            'payment_date' => '2026-10-10', 'amount' => 1000, 'currency' => 'PHP',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('balance', '0.0000');
        $paymentId = $paymentResponse->json('data.0.id');
        $invoiceId = $paymentResponse->json('invoice.id');

        $partial = $this->postJson('/api/customer-refunds', [
            'original_payment_id' => $paymentId,
            'financial_account_id' => $account->id,
            'payment_method_id' => $method->id,
            'refund_date' => '2026-10-10', 'currency' => 'PHP', 'amount' => 500,
            'reason' => 'Customer returned half of the items',
            'idempotency_key' => (string) Str::uuid(), 'post' => true,
        ])->assertCreated()->assertJsonPath('status', 'posted');

        $this->assertDatabaseHas('sales_invoices', ['id' => $invoiceId, 'status' => 'partially_refunded']);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $cashLedger->id, 'debit' => 0, 'credit' => 500,
        ]);
        $this->getJson('/api/integrations/customer-order?source=bakingstore-branch-1&external_order_id=901')
            ->assertOk()
            ->assertJsonPath('original_total', '1000.0000')
            ->assertJsonPath('total_received', '1000.0000')
            ->assertJsonPath('total_refunded', '500.0000')
            ->assertJsonPath('net_total', '500.0000')
            ->assertJsonPath('total_payment', '500.0000')
            ->assertJsonPath('balance', '0.0000')
            ->assertJsonPath('payment_entries.0.type', 'payment')
            ->assertJsonPath('payment_entries.0.transaction_id', 901)
            ->assertJsonPath('payment_entries.0.amount', '1000.0000')
            ->assertJsonPath('payment_entries.1.type', 'refund')
            ->assertJsonPath('payment_entries.1.transaction_id', 901)
            ->assertJsonPath('payment_entries.1.linked_payment_id', $paymentId)
            ->assertJsonPath('payment_entries.1.amount', '-500.0000')
            ->assertJsonPath('data.0.refundable_amount', '500.0000');

        $this->getJson('/api/integrations/customer-payment-report?source=bakingstore-branch-1&date_from=2026-10-10&date_to=2026-10-10')
            ->assertOk()
            ->assertJsonPath('total_sales', '500.0000')
            ->assertJsonPath('total_refunded', '500.0000')
            ->assertJsonPath('data.0.orders.0.external_order_id', 901)
            ->assertJsonPath('data.0.orders.0.gross_received', '1000.0000')
            ->assertJsonPath('data.0.orders.0.refunds', '500.0000')
            ->assertJsonPath('data.0.orders.0.total_sales', '500.0000')
            ->assertJsonPath('data.0.accounts.0.amount', '500.0000');

        $full = $this->postJson('/api/customer-refunds', [
            'original_payment_id' => $paymentId,
            'financial_account_id' => $account->id,
            'payment_method_id' => $method->id,
            'refund_date' => '2026-10-10', 'currency' => 'PHP', 'amount' => 500,
            'reason' => 'Customer returned the remaining items',
            'post' => true,
        ])->assertCreated()->assertJsonPath('status', 'posted');

        $this->assertDatabaseHas('sales_invoices', ['id' => $invoiceId, 'status' => 'refunded']);
        $this->getJson('/api/integrations/customer-order?source=bakingstore-branch-1&external_order_id=901')
            ->assertOk()
            ->assertJsonPath('total_refunded', '1000.0000')
            ->assertJsonPath('net_total', '0.0000')
            ->assertJsonPath('total_payment', '0.0000')
            ->assertJsonPath('balance', '0.0000');

        $this->postJson('/api/customer-refunds', [
            'original_payment_id' => $paymentId,
            'financial_account_id' => $account->id,
            'payment_method_id' => $method->id,
            'refund_date' => '2026-10-10', 'currency' => 'PHP', 'amount' => 1,
            'reason' => 'Excessive refund', 'post' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->postJson("/api/payments/{$paymentId}/void", ['reason' => 'Cannot void after refund'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->postJson('/api/customer-refunds/'.$full->json('id').'/void', ['reason' => 'Return was cancelled'])
            ->assertOk()->assertJsonPath('status', 'voided');
        $this->assertDatabaseHas('sales_invoices', ['id' => $invoiceId, 'status' => 'partially_refunded']);
        $this->assertDatabaseHas('journal_entries', [
            'customer_refund_id' => $full->json('id'), 'kind' => 'customer_refund_reversal',
        ]);

        $this->postJson('/api/customer-refunds/'.$partial->json('id').'/void', ['reason' => 'All returns cancelled'])
            ->assertOk();
        $this->assertDatabaseHas('sales_invoices', ['id' => $invoiceId, 'status' => 'paid']);
    }

    public function test_refunds_support_cash_bank_transfer_and_e_wallet_methods(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach ([
            ['cash', 'cash', 'CASH'],
            ['bank_account', 'bank_transfer', 'BANK'],
            ['e_wallet', 'e_wallet', 'WALLET'],
        ] as $index => [$accountKind, $methodType, $suffix]) {
            $ledger = ChartAccount::create([
                'code' => '10'.($index + 10),
                'name' => $suffix.' Account',
                'type' => 'asset',
            ]);
            $account = FinancialAccount::create([
                'name' => $suffix.' Account',
                'kind' => $accountKind,
                'currency' => 'PHP',
                'chart_account_id' => $ledger->id,
                'is_company_wide' => true,
                'usable_for_receipts' => true,
                'usable_for_payments' => true,
            ]);
            $account->applications()->create([
                'application' => FinancialAccountApplication::CUSTOMER_RECEIPT,
                'source' => 'manual',
            ]);
            $method = PaymentMethod::create([
                'code' => 'REFUND-'.$suffix,
                'name' => $suffix,
                'type' => $methodType,
            ]);

            $payment = $this->postJson('/api/integrations/customer-order/payments', [
                'source' => 'refund-method-test',
                'external_branch_id' => 1,
                'branch_name' => 'Main Branch',
                'external_customer_id' => 100 + $index,
                'customer_name' => 'Customer '.$suffix,
                'external_order_id' => 1000 + $index,
                'order_date' => '2026-10-20',
                'order_total' => 1000,
                'financial_account_id' => $account->id,
                'payment_method_id' => $method->id,
                'payment_date' => '2026-10-20',
                'amount' => 1000,
                'currency' => 'PHP',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertCreated();

            $this->postJson('/api/customer-refunds', [
                'original_payment_id' => $payment->json('data.0.id'),
                'financial_account_id' => $account->id,
                'payment_method_id' => $method->id,
                'refund_date' => '2026-10-21',
                'currency' => 'PHP',
                'amount' => 250,
                'reason' => 'Customer refund via '.$methodType,
                'post' => true,
            ])->assertCreated()
                ->assertJsonPath('status', 'posted')
                ->assertJsonPath('payment_method.type', $methodType);
        }
    }

    public function test_refund_rejects_a_method_that_does_not_match_the_funding_account(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cashLedger = ChartAccount::create(['code' => '1010', 'name' => 'Cash', 'type' => 'asset']);
        $cash = FinancialAccount::create([
            'name' => 'Cash', 'kind' => 'cash', 'currency' => 'PHP',
            'chart_account_id' => $cashLedger->id, 'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $cash->applications()->create([
            'application' => FinancialAccountApplication::CUSTOMER_RECEIPT,
            'source' => 'manual',
        ]);
        $cashMethod = PaymentMethod::create(['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash']);
        $bankMethod = PaymentMethod::create(['code' => 'BANK', 'name' => 'Bank', 'type' => 'bank_transfer']);
        $payment = $this->postJson('/api/integrations/customer-order/payments', [
            'source' => 'refund-mismatch-test',
            'external_branch_id' => 1, 'branch_name' => 'Main Branch',
            'external_customer_id' => 1, 'customer_name' => 'Customer',
            'external_order_id' => 1, 'order_date' => '2026-10-20', 'order_total' => 1000,
            'financial_account_id' => $cash->id, 'payment_method_id' => $cashMethod->id,
            'payment_date' => '2026-10-20', 'amount' => 1000, 'currency' => 'PHP',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->postJson('/api/customer-refunds', [
            'original_payment_id' => $payment->json('data.0.id'),
            'financial_account_id' => $cash->id,
            'payment_method_id' => $bankMethod->id,
            'refund_date' => '2026-10-21', 'currency' => 'PHP', 'amount' => 250,
            'reason' => 'Invalid method pairing', 'post' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method_id');
    }
}
