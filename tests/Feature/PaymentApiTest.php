<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Counterparty;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\PaymentMethod;
use App\Models\PaymentObligation;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_payment_posts_a_balanced_inflow(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$account, $offset, $method, $branch] = $this->accounts('asset');
        $this->assertNotSame('001', DB::table('financial_accounts')->where('id', $account->id)->value('account_number'));
        $this->assertArrayNotHasKey('account_number', $account->toArray());
        $this->assertSame('•••• 001', $account->masked_account_number);
        $customer = Counterparty::create(['type' => 'customer', 'code' => 'CUS-1', 'name' => 'Test Customer']);
        $invoice = SalesInvoice::create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'number' => 'INV-1',
            'invoice_date' => '2026-10-04', 'currency' => 'PHP', 'total_amount' => 1250,
        ]);
        $gcashLedger = ChartAccount::create(['code' => '1002', 'name' => 'Shared GCash', 'type' => 'asset']);
        $gcash = FinancialAccount::create([
            'name' => 'Shared GCash', 'kind' => 'e_wallet', 'account_number' => '09170000000',
            'currency' => 'PHP', 'chart_account_id' => $gcashLedger->id,
            'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);

        $response = $this->postJson('/api/payments', [
            'branch_id' => $branch->id, 'type' => 'customer', 'sales_invoice_id' => $invoice->id,
            'counterparty_id' => $customer->id, 'offset_account_id' => $offset->id,
            'payment_date' => '2026-10-04', 'currency' => 'PHP',
            'lines' => [
                ['financial_account_id' => $account->id, 'payment_method_id' => $method->id, 'amount' => 750],
                ['financial_account_id' => $gcash->id, 'payment_method_id' => $method->id, 'amount' => 500, 'reference' => 'GCASH-REF'],
            ],
            'post' => true,
        ])->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('amount', '1250.0000');

        $paymentId = $response->json('id');
        $this->assertDatabaseHas('journal_lines', ['chart_account_id' => $account->chart_account_id, 'debit' => 750, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['chart_account_id' => $gcash->chart_account_id, 'debit' => 500, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['chart_account_id' => $offset->id, 'debit' => 0, 'credit' => 1250]);
        $this->assertDatabaseHas('journal_entries', ['payment_id' => $paymentId, 'kind' => 'payment']);
        $this->assertDatabaseHas('sales_invoices', ['id' => $invoice->id, 'status' => 'paid']);
        $otherBranch = $this->branch('B2');
        $this->getJson('/api/payments')->assertOk()->assertJsonPath('data.0.branch_id', $branch->id);
        $this->getJson("/api/financial-accounts?branch_id={$otherBranch->id}")
            ->assertOk()->assertJsonPath('data.0.id', $account->id);
    }

    public function test_outflow_can_be_posted_then_voided_with_a_reversal(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$account, $offset, $method, $branch] = $this->accounts('expense');
        $obligation = PaymentObligation::create([
            'number' => 'OBL-1', 'type' => 'other', 'due_date' => '2099-10-20',
            'currency' => 'PHP', 'principal_amount' => 500,
        ]);

        $payment = $this->postJson('/api/payments', [
            'branch_id' => $branch->id, 'type' => 'chart_account',
            'offset_account_id' => $offset->id, 'payment_date' => '2026-10-04',
            'currency' => 'PHP',
            'lines' => [['financial_account_id' => $account->id, 'payment_method_id' => $method->id, 'amount' => 500]],
            'allocations' => [['payment_obligation_id' => $obligation->id, 'amount' => 500]],
        ])->assertCreated()->assertJsonPath('status', 'draft')->json();

        $this->postJson("/api/payments/{$payment['id']}/post")->assertOk()->assertJsonPath('status', 'posted');
        $this->assertDatabaseHas('payment_obligations', ['id' => $obligation->id, 'status' => 'paid']);
        $this->postJson("/api/payments/{$payment['id']}/void", ['reason' => 'Duplicate payment'])
            ->assertOk()->assertJsonPath('status', 'voided');

        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseHas('journal_lines', ['chart_account_id' => $account->chart_account_id, 'debit' => 500, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['chart_account_id' => $offset->id, 'debit' => 0, 'credit' => 500]);
        $this->assertDatabaseHas('payment_obligations', ['id' => $obligation->id, 'status' => 'open']);
    }

    public function test_financial_account_application_restricts_the_transaction_type(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$account, $offset, $method, $branch] = $this->accounts('expense');
        $account->applications()->create([
            'application' => FinancialAccountApplication::CUSTOMER_RECEIPT,
            'source' => 'manual',
        ]);

        $payload = [
            'branch_id' => $branch->id,
            'type' => 'chart_account',
            'offset_account_id' => $offset->id,
            'payment_date' => '2026-10-04',
            'currency' => 'PHP',
            'lines' => [[
                'financial_account_id' => $account->id,
                'payment_method_id' => $method->id,
                'amount' => 500,
            ]],
        ];

        $this->postJson('/api/payments', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.financial_account_id');

        $account->applications()->create([
            'application' => FinancialAccountApplication::DIRECT_PAYMENT,
            'source' => 'manual',
        ]);

        $this->postJson('/api/payments', $payload)->assertCreated();
    }

    public function test_one_account_can_be_shared_with_selected_branches_only(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$account, $offset, $method, $branchOne] = $this->accounts('expense');
        $branchTwo = $this->branch('B2');
        $branchThree = $this->branch('B3');
        $account->update(['is_company_wide' => false]);
        $account->accessibleBranches()->sync([$branchOne->id, $branchTwo->id]);

        $this->getJson("/api/financial-accounts?branch_id={$branchTwo->id}")
            ->assertOk()->assertJsonFragment(['id' => $account->id]);
        $this->getJson("/api/financial-accounts?branch_id={$branchThree->id}")
            ->assertOk()->assertJsonMissing(['id' => $account->id]);

        $payload = [
            'type' => 'chart_account',
            'offset_account_id' => $offset->id,
            'payment_date' => '2026-10-04',
            'currency' => 'PHP',
            'lines' => [[
                'financial_account_id' => $account->id,
                'payment_method_id' => $method->id,
                'amount' => 500,
            ]],
        ];

        $this->postJson('/api/payments', $payload + ['branch_id' => $branchThree->id])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.financial_account_id');
        $this->postJson('/api/payments', $payload + ['branch_id' => $branchTwo->id])->assertCreated();
    }

    private function accounts(string $offsetType): array
    {
        $bankLedger = ChartAccount::create(['code' => '1001', 'name' => 'Test Bank', 'type' => 'asset']);
        $offset = ChartAccount::create(['code' => '5001', 'name' => 'Offset', 'type' => $offsetType]);
        $account = FinancialAccount::create([
            'name' => 'Operating', 'kind' => 'bank_account', 'account_number' => '001',
            'currency' => 'PHP', 'chart_account_id' => $bankLedger->id,
            'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $method = PaymentMethod::create(['code' => 'TEST', 'name' => 'Test Method', 'type' => 'bank_transfer']);
        $branch = $this->branch('B1');

        return [$account, $offset, $method, $branch];
    }

    private function branch(string $prefix): Branch
    {
        $inventory = ChartAccount::create(['code' => $prefix.'-INV', 'name' => $prefix.' Inventory', 'type' => 'asset']);
        $dueFrom = ChartAccount::create(['code' => $prefix.'-DF', 'name' => $prefix.' Due From', 'type' => 'asset']);
        $dueTo = ChartAccount::create(['code' => $prefix.'-DT', 'name' => $prefix.' Due To', 'type' => 'liability']);

        return Branch::create([
            'code' => $prefix, 'name' => $prefix.' Branch', 'inventory_account_id' => $inventory->id,
            'due_from_account_id' => $dueFrom->id, 'due_to_account_id' => $dueTo->id,
        ]);
    }
}
