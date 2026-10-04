<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Counterparty;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EndOfDaySettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_branches_can_settle_gcash_to_one_bdo_account(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $branchOne = $this->branch('B1');
        $branchTwo = $this->branch('B2');
        $receivable = ChartAccount::create(['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset']);
        $gcashLedger = ChartAccount::create(['code' => '1010', 'name' => 'GCash Ced', 'type' => 'asset']);
        $bdoLedger = ChartAccount::create(['code' => '1020', 'name' => 'BDO Ced', 'type' => 'asset']);
        $openingEquity = ChartAccount::create(['code' => '3000', 'name' => 'Opening Equity', 'type' => 'equity']);
        $gcash = FinancialAccount::create([
            'name' => 'GCash Ced', 'kind' => 'e_wallet', 'currency' => 'PHP',
            'chart_account_id' => $gcashLedger->id, 'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $gcash->applications()->create(['application' => FinancialAccountApplication::CUSTOMER_RECEIPT, 'source' => 'manual']);
        $bdo = FinancialAccount::create([
            'name' => 'BDO Ced', 'kind' => 'bank_account', 'currency' => 'PHP',
            'chart_account_id' => $bdoLedger->id, 'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $bdo->applications()->create(['application' => FinancialAccountApplication::END_OF_DAY_SETTLEMENT, 'source' => 'manual']);
        $method = PaymentMethod::create(['code' => 'GCASH', 'name' => 'GCash', 'type' => 'e_wallet']);
        $customer = Counterparty::create(['type' => 'customer', 'code' => 'CUS-1', 'name' => 'Customer']);

        $opening = JournalEntry::create([
            'kind' => 'payment', 'number' => 'OPEN-BDO', 'entry_date' => '2026-10-03',
            'memo' => 'Opening BDO balance', 'created_by' => $user->id,
        ]);
        $opening->lines()->createMany([
            ['branch_id' => $branchOne->id, 'chart_account_id' => $bdoLedger->id, 'debit' => 1000, 'credit' => 0],
            ['branch_id' => $branchOne->id, 'chart_account_id' => $openingEquity->id, 'debit' => 0, 'credit' => 1000],
        ]);

        $paymentIds = [];
        foreach ([$branchOne, $branchTwo] as $index => $branch) {
            $invoice = SalesInvoice::create([
                'branch_id' => $branch->id, 'customer_id' => $customer->id,
                'number' => 'INV-'.($index + 1), 'invoice_date' => '2026-10-04',
                'currency' => 'PHP', 'total_amount' => 1000,
            ]);
            $paymentIds[] = $this->postJson('/api/payments', [
                'branch_id' => $branch->id, 'type' => 'customer', 'sales_invoice_id' => $invoice->id,
                'offset_account_id' => $receivable->id, 'payment_date' => '2026-10-04', 'currency' => 'PHP',
                'lines' => [[
                    'financial_account_id' => $gcash->id, 'payment_method_id' => $method->id, 'amount' => 1000,
                ]],
                'post' => true,
            ])->assertCreated()->json('id');
        }

        $settlement = $this->postJson('/api/end-of-day-settlements', [
            'branch_ids' => [$branchOne->id, $branchTwo->id],
            'business_date' => '2026-10-04',
            'destination_financial_account_id' => $bdo->id,
            'currency' => 'PHP',
            'memo' => 'Deposit both branches GCash collections to BDO Ced',
        ])->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('gross_collection_amount', '2000.0000')
            ->assertJsonPath('transfer_amount', '2000.0000')
            ->assertJsonCount(2, 'items')
            ->json();

        $this->postJson("/api/end-of-day-settlements/{$settlement['id']}/post")
            ->assertOk()->assertJsonPath('status', 'posted');

        $this->getJson("/api/financial-accounts/{$gcash->id}/balance")
            ->assertOk()->assertJsonPath('balance', '0.0000');
        $this->getJson("/api/financial-accounts/{$bdo->id}/balance")
            ->assertOk()->assertJsonPath('balance', '3000.0000');
        $this->assertDatabaseHas('journal_lines', [
            'branch_id' => $branchOne->id, 'chart_account_id' => $bdoLedger->id,
            'debit' => 1000, 'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'branch_id' => $branchTwo->id, 'chart_account_id' => $bdoLedger->id,
            'debit' => 1000, 'credit' => 0,
        ]);

        $this->postJson('/api/end-of-day-settlements', [
            'branch_ids' => [$branchOne->id, $branchTwo->id],
            'business_date' => '2026-10-04', 'destination_financial_account_id' => $bdo->id,
            'currency' => 'PHP',
        ])->assertUnprocessable();
        $this->postJson("/api/payments/{$paymentIds[0]}/void", ['reason' => 'Correction'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->postJson("/api/end-of-day-settlements/{$settlement['id']}/void", ['reason' => 'Bank transfer reversed'])
            ->assertOk()->assertJsonPath('status', 'voided');
        $this->postJson("/api/payments/{$paymentIds[0]}/void", ['reason' => 'Correction'])
            ->assertOk()->assertJsonPath('status', 'voided');
    }

    private function branch(string $code): Branch
    {
        $inventory = ChartAccount::create(['code' => $code.'-INV', 'name' => $code.' Inventory', 'type' => 'asset']);
        $dueFrom = ChartAccount::create(['code' => $code.'-DF', 'name' => $code.' Due From', 'type' => 'asset']);
        $dueTo = ChartAccount::create(['code' => $code.'-DT', 'name' => $code.' Due To', 'type' => 'liability']);

        return Branch::create([
            'code' => $code, 'name' => $code.' Branch', 'inventory_account_id' => $inventory->id,
            'due_from_account_id' => $dueFrom->id, 'due_to_account_id' => $dueTo->id,
        ]);
    }
}
