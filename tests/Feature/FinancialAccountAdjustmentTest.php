<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\JournalEntry;
use App\Models\PaymentObligation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialAccountAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_bank_balance_creates_an_audited_reconciliation_transaction(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $bankLedger = ChartAccount::create(['code' => '1020', 'name' => 'BDO Ced', 'type' => 'asset']);
        $equity = ChartAccount::create(['code' => '3000', 'name' => 'Opening Equity', 'type' => 'equity']);
        $differenceAccount = ChartAccount::create(['code' => '6990', 'name' => 'Bank Reconciliation Difference', 'type' => 'expense']);
        $bank = FinancialAccount::create([
            'name' => 'BDO Ced', 'kind' => 'bank_account', 'currency' => 'PHP',
            'chart_account_id' => $bankLedger->id, 'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $bank->applications()->create([
            'application' => FinancialAccountApplication::BALANCE_ADJUSTMENT,
            'source' => 'manual',
        ]);
        $opening = JournalEntry::create([
            'kind' => 'payment', 'number' => 'OPEN-BDO', 'entry_date' => '2026-10-03',
            'memo' => 'Opening balance', 'created_by' => $user->id,
        ]);
        $opening->lines()->createMany([
            ['chart_account_id' => $bankLedger->id, 'debit' => 3000, 'credit' => 0],
            ['chart_account_id' => $equity->id, 'debit' => 0, 'credit' => 3000],
        ]);

        $adjustment = $this->postJson('/api/account-adjustments', [
            'financial_account_id' => $bank->id,
            'adjustment_account_id' => $differenceAccount->id,
            'adjustment_date' => '2026-10-04',
            'currency' => 'PHP',
            'actual_balance' => 2950,
            'reference' => 'BDO-STATEMENT-2026-10-04',
            'reason' => 'Bank service charge missing from the system',
            'post' => true,
        ])->assertCreated()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('system_balance_before', '3000.0000')
            ->assertJsonPath('actual_balance', '2950.0000')
            ->assertJsonPath('adjustment_amount', '-50.0000')
            ->json();

        $this->assertDatabaseHas('financial_account_adjustments', [
            'id' => $adjustment['id'], 'system_balance_before' => 3000,
            'actual_balance' => 2950, 'adjustment_amount' => -50,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $bankLedger->id, 'debit' => 0, 'credit' => 50,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $differenceAccount->id, 'debit' => 50, 'credit' => 0,
        ]);
        $this->getJson("/api/financial-accounts/{$bank->id}/balance")
            ->assertOk()->assertJsonPath('balance', '2950.0000');

        $this->postJson("/api/account-adjustments/{$adjustment['id']}/void", ['reason' => 'Statement value was incorrect'])
            ->assertOk()->assertJsonPath('status', 'voided');
        $this->getJson("/api/financial-accounts/{$bank->id}/balance")
            ->assertOk()->assertJsonPath('balance', '3000.0000');
    }

    public function test_credit_card_soa_adjustment_updates_liability_and_statement_due(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $cardLedger = ChartAccount::create(['code' => '2100', 'name' => 'Credit Card Payable', 'type' => 'liability']);
        $purchases = ChartAccount::create(['code' => '5100', 'name' => 'Purchases', 'type' => 'expense']);
        $differenceAccount = ChartAccount::create(['code' => '6190', 'name' => 'Card SOA Difference', 'type' => 'expense']);
        $card = FinancialAccount::create([
            'name' => 'BDO Credit Card', 'kind' => 'credit_card', 'currency' => 'PHP',
            'chart_account_id' => $cardLedger->id, 'is_company_wide' => true,
            'usable_for_receipts' => false, 'usable_for_payments' => true,
            'credit_limit' => 100000, 'statement_day' => 17, 'due_day' => 7,
        ]);
        $card->applications()->create([
            'application' => FinancialAccountApplication::BALANCE_ADJUSTMENT,
            'source' => 'manual',
        ]);
        $statement = PaymentObligation::create([
            'number' => 'CCS-1-20261017', 'type' => 'credit_card_statement',
            'financial_account_id' => $card->id, 'offset_account_id' => $cardLedger->id,
            'statement_date' => '2026-10-17', 'due_date' => '2026-11-07',
            'currency' => 'PHP', 'principal_amount' => 10000,
        ]);
        $charge = JournalEntry::create([
            'kind' => 'payment', 'number' => 'CARD-OPEN', 'entry_date' => '2026-10-17',
            'memo' => 'Card charges', 'created_by' => $user->id,
        ]);
        $charge->lines()->createMany([
            ['chart_account_id' => $purchases->id, 'debit' => 10000, 'credit' => 0],
            ['chart_account_id' => $cardLedger->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $adjustment = $this->postJson('/api/account-adjustments', [
            'financial_account_id' => $card->id,
            'payment_obligation_id' => $statement->id,
            'adjustment_account_id' => $differenceAccount->id,
            'adjustment_date' => '2026-10-17',
            'currency' => 'PHP',
            'actual_balance' => 10250,
            'reference' => 'BDO-SOA-2026-10',
            'reason' => 'SOA contains an unrecorded annual fee',
            'post' => true,
        ])->assertCreated()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('system_balance_before', '10000.0000')
            ->assertJsonPath('adjustment_amount', '250.0000')
            ->assertJsonPath('payment_obligation.total_amount', '10250.0000')
            ->json();

        $this->assertDatabaseHas('payment_obligations', [
            'id' => $statement->id, 'adjustment_amount' => 250,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $cardLedger->id, 'debit' => 0, 'credit' => 250,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $differenceAccount->id, 'debit' => 250, 'credit' => 0,
        ]);
        $this->getJson("/api/financial-accounts/{$card->id}/balance")
            ->assertOk()->assertJsonPath('balance', '10250.0000');

        $this->postJson("/api/account-adjustments/{$adjustment['id']}/void", ['reason' => 'Bank corrected the SOA'])
            ->assertOk()->assertJsonPath('payment_obligation.total_amount', '10000.0000');
        $this->assertDatabaseHas('payment_obligations', [
            'id' => $statement->id, 'adjustment_amount' => 0,
        ]);
    }
}
