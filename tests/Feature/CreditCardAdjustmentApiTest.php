<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\PaymentObligation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditCardAdjustmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_card_due_creates_a_detailed_audited_adjustment_transaction(): void
    {
        $user = User::factory()->create(['name' => 'Accounting User']);
        Sanctum::actingAs($user);

        $cardLedger = ChartAccount::create([
            'code' => '2100', 'name' => 'BDO AMEX Payable', 'type' => 'liability',
        ]);
        $differenceAccount = ChartAccount::create([
            'code' => '6190', 'name' => 'Credit Card Reconciliation Difference', 'type' => 'expense',
        ]);
        $inventory = ChartAccount::create(['code' => '1300', 'name' => 'Inventory', 'type' => 'asset']);
        $dueFrom = ChartAccount::create(['code' => '1310', 'name' => 'Due From', 'type' => 'asset']);
        $dueTo = ChartAccount::create(['code' => '2310', 'name' => 'Due To', 'type' => 'liability']);
        $branch = Branch::create([
            'code' => 'MAIN', 'name' => 'Main Branch', 'inventory_account_id' => $inventory->id,
            'due_from_account_id' => $dueFrom->id, 'due_to_account_id' => $dueTo->id,
        ]);
        $card = FinancialAccount::create([
            'name' => 'BDO - AMEX',
            'kind' => 'credit_card',
            'currency' => 'PHP',
            'chart_account_id' => $cardLedger->id,
            'is_company_wide' => true,
            'usable_for_payments' => true,
            'credit_limit' => 500000,
            'statement_day' => 15,
            'due_day' => 5,
        ]);
        $card->applications()->create([
            'application' => 'purchase_order_payment',
            'source' => 'manual',
        ]);
        $statement = PaymentObligation::create([
            'number' => 'CCS-BDO-AMEX-202610',
            'type' => 'credit_card_statement',
            'financial_account_id' => $card->id,
            'offset_account_id' => $cardLedger->id,
            'statement_date' => '2026-10-15',
            'due_date' => '2026-11-05',
            'currency' => 'PHP',
            'principal_amount' => 120000,
            'status' => 'partially_paid',
        ]);
        $payment = Payment::create([
            'branch_id' => $branch->id,
            'number' => 'PAY-BDO-AMEX-1',
            'type' => 'chart_account',
            'direction' => 'outflow',
            'offset_account_id' => $cardLedger->id,
            'payment_date' => '2026-10-16',
            'currency' => 'PHP',
            'amount' => 20000,
            'status' => 'posted',
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);
        $payment->allocations()->create([
            'payment_obligation_id' => $statement->id,
            'amount' => 20000,
        ]);

        $adjustment = $this->postJson("/api/credit-cards/{$card->id}/adjustments", [
            'payment_obligation_id' => $statement->id,
            'adjustment_account_id' => $differenceAccount->id,
            'actual_amount_due' => 101000,
            'adjustment_date' => '2026-10-16',
            'reference' => 'BDO-AMEX-SOA-2026-10',
            'reason' => 'Actual SOA includes an unrecorded annual fee.',
        ])->assertCreated()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('expected_amount_due_before', '100000.0000')
            ->assertJsonPath('paid_amount_before', '20000.0000')
            ->assertJsonPath('actual_amount_due', '101000.0000')
            ->assertJsonPath('adjustment_amount', '1000.0000')
            ->assertJsonPath('direction', 'INCREASE')
            ->assertJsonPath('credit_card.id', $card->id)
            ->assertJsonPath('statement.id', $statement->id)
            ->assertJsonPath('statement.total_amount', '121000.0000')
            ->assertJsonPath('adjustment_account.id', $differenceAccount->id)
            ->assertJsonPath('audit.created_by.id', $user->id)
            ->assertJsonPath('audit.posted_by.id', $user->id)
            ->assertJsonPath('journal_transactions.0.kind', 'account_balance_adjustment')
            ->json();

        $this->assertDatabaseHas('financial_account_adjustments', [
            'id' => $adjustment['id'],
            'financial_account_id' => $card->id,
            'payment_obligation_id' => $statement->id,
            'expected_amount_due_before' => 100000,
            'paid_amount_before' => 20000,
            'actual_amount_due' => 101000,
            'adjustment_amount' => 1000,
            'status' => 'posted',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $cardLedger->id, 'debit' => 0, 'credit' => 1000,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $differenceAccount->id, 'debit' => 1000, 'credit' => 0,
        ]);

        $this->getJson("/api/credit-cards/{$card->id}/adjustments")
            ->assertOk()
            ->assertJsonPath('data.0.id', $adjustment['id'])
            ->assertJsonPath('data.0.reference', 'BDO-AMEX-SOA-2026-10');

        $this->getJson("/api/credit-cards/{$card->id}/adjustments/{$adjustment['id']}")
            ->assertOk()
            ->assertJsonPath('journal_transactions.0.lines.0.credit', '1000.0000');

        $this->getJson("/api/credit-cards/{$card->id}?as_of=2026-10-16")
            ->assertOk()
            ->assertJsonPath('amount_due', '101000.0000');

        $this->postJson("/api/credit-cards/{$card->id}/adjustments/{$adjustment['id']}/void", [
            'reason' => 'The bank issued a corrected SOA.',
        ])->assertOk()
            ->assertJsonPath('status', 'voided')
            ->assertJsonPath('statement.total_amount', '120000.0000')
            ->assertJsonPath('audit.voided_by.id', $user->id)
            ->assertJsonPath('journal_transactions.1.kind', 'account_balance_adjustment_reversal');
    }

    public function test_statement_must_belong_to_the_credit_card(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $ledger = ChartAccount::create(['code' => '2110', 'name' => 'Card One', 'type' => 'liability']);
        $otherLedger = ChartAccount::create(['code' => '2120', 'name' => 'Card Two', 'type' => 'liability']);
        $difference = ChartAccount::create(['code' => '6191', 'name' => 'Difference', 'type' => 'expense']);
        $card = FinancialAccount::create([
            'name' => 'Card One', 'kind' => 'credit_card', 'currency' => 'PHP',
            'chart_account_id' => $ledger->id, 'credit_limit' => 10000,
            'statement_day' => 1, 'due_day' => 20, 'is_company_wide' => true,
        ]);
        $otherCard = FinancialAccount::create([
            'name' => 'Card Two', 'kind' => 'credit_card', 'currency' => 'PHP',
            'chart_account_id' => $otherLedger->id, 'credit_limit' => 10000,
            'statement_day' => 1, 'due_day' => 20, 'is_company_wide' => true,
        ]);
        $otherStatement = PaymentObligation::create([
            'number' => 'OTHER-STATEMENT', 'type' => 'credit_card_statement',
            'financial_account_id' => $otherCard->id, 'offset_account_id' => $otherLedger->id,
            'statement_date' => '2026-10-01', 'due_date' => '2026-10-20',
            'currency' => 'PHP', 'principal_amount' => 1000,
        ]);

        $this->postJson("/api/credit-cards/{$card->id}/adjustments", [
            'payment_obligation_id' => $otherStatement->id,
            'adjustment_account_id' => $difference->id,
            'actual_amount_due' => 1100,
            'adjustment_date' => '2026-10-13',
            'reason' => 'Mismatch',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('payment_obligation_id');
    }
}
