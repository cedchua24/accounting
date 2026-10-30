<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\PaymentObligation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditCardPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_card_supports_partial_exact_and_overpayments(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $branch = $this->branch();
        $bankLedger = ChartAccount::create(['code' => '1010', 'name' => 'Operating Bank', 'type' => 'asset']);
        $cardLedger = ChartAccount::create(['code' => '2010', 'name' => 'Credit Card Payable', 'type' => 'liability']);
        $equity = ChartAccount::create(['code' => '3010', 'name' => 'Opening Equity', 'type' => 'equity']);
        $expense = ChartAccount::create(['code' => '5010', 'name' => 'Card Purchases', 'type' => 'expense']);
        $bank = FinancialAccount::create([
            'name' => 'Operating Bank', 'kind' => 'bank_account', 'currency' => 'PHP',
            'chart_account_id' => $bankLedger->id, 'is_company_wide' => true,
            'usable_for_payments' => true,
        ]);
        $bank->applications()->create([
            'application' => 'expense_payment',
            'source' => 'manual',
        ]);
        $card = FinancialAccount::create([
            'name' => 'Corporate Card', 'kind' => 'credit_card', 'currency' => 'PHP',
            'chart_account_id' => $cardLedger->id, 'is_company_wide' => true,
            'credit_limit' => 10000, 'statement_day' => 17, 'due_day' => 7,
        ]);
        $method = PaymentMethod::create([
            'code' => 'BANK', 'name' => 'Bank Transfer', 'type' => 'bank_transfer',
        ]);
        $walletLedger = ChartAccount::create(['code' => '1020', 'name' => 'Payment Wallet', 'type' => 'asset']);
        $wallet = FinancialAccount::create([
            'name' => 'Payment Wallet', 'kind' => 'e_wallet', 'currency' => 'PHP',
            'chart_account_id' => $walletLedger->id, 'is_company_wide' => true,
            'usable_for_payments' => true,
        ]);
        $cheque = PaymentMethod::create([
            'code' => 'CHEQUE', 'name' => 'Cheque', 'type' => 'cheque',
        ]);
        $firstStatement = $this->statement($card, $branch, 'CCS-1', '2026-10-17', '2026-11-07', 1000);
        $secondStatement = $this->statement($card, $branch, 'CCS-2', '2026-11-17', '2026-12-07', 500);
        $thirdStatement = $this->statement($card, $branch, 'CCS-3', '2026-12-17', '2027-01-07', 300);
        $opening = JournalEntry::create([
            'kind' => 'payment', 'number' => 'OPENING-CARD-PAYMENT-TEST', 'entry_date' => '2026-10-01',
        ]);
        $opening->lines()->createMany([
            ['chart_account_id' => $bankLedger->id, 'debit' => 5000, 'credit' => 0],
            ['chart_account_id' => $equity->id, 'debit' => 0, 'credit' => 5000],
            ['chart_account_id' => $expense->id, 'debit' => 1800, 'credit' => 0],
            ['chart_account_id' => $cardLedger->id, 'debit' => 0, 'credit' => 1800],
        ]);

        $basePayload = [
            'branch_id' => $branch->id,
            'funding_account_id' => $bank->id,
            'payment_method_id' => $method->id,
            'payment_date' => '2026-10-12',
        ];

        $this->getJson("/api/credit-cards/{$card->id}/funding-accounts?payment_method_id={$method->id}&branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('credit_card_id', $card->id)
            ->assertJsonPath('payment_method.id', $method->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bank->id)
            ->assertJsonMissing(['id' => $wallet->id]);

        $this->getJson("/api/credit-cards/{$card->id}/funding-accounts?payment_method_id={$cheque->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method_id');

        $this->postJson("/api/credit-cards/{$card->id}/payments", $basePayload + [
            'statement_id' => $firstStatement->id,
            'amount' => 400,
            'reference' => 'CARD-PARTIAL',
        ])->assertCreated()
            ->assertJsonPath('payment_mode', 'partial')
            ->assertJsonPath('allocated_amount', '400.0000')
            ->assertJsonPath('overpayment_amount', '0.0000')
            ->assertJsonPath('amount_due_after', '600.0000')
            ->assertJsonPath('payment.status', 'posted');
        $this->assertDatabaseHas('payment_obligations', ['id' => $firstStatement->id, 'status' => 'partially_paid']);
        $this->assertSame('4600.0000', $bank->fresh()->current_balance);
        $this->assertSame('1400.0000', $card->fresh()->current_balance);
        $this->assertSame('8600.0000', $card->fresh()->available_balance);

        $this->postJson("/api/credit-cards/{$card->id}/payments", $basePayload + [
            'statement_id' => $firstStatement->id,
            'amount' => 600,
            'reference' => 'CARD-EXACT',
        ])->assertCreated()
            ->assertJsonPath('payment_mode', 'exact')
            ->assertJsonPath('allocated_amount', '600.0000')
            ->assertJsonPath('overpayment_amount', '0.0000')
            ->assertJsonPath('amount_due_after', '0.0000');
        $this->assertDatabaseHas('payment_obligations', ['id' => $firstStatement->id, 'status' => 'paid']);
        $this->assertSame('4000.0000', $bank->fresh()->current_balance);
        $this->assertSame('800.0000', $card->fresh()->current_balance);
        $this->assertSame('9200.0000', $card->fresh()->available_balance);

        $this->postJson("/api/credit-cards/{$card->id}/payments", $basePayload + [
            'statement_id' => $secondStatement->id,
            'amount' => 700,
            'reference' => 'CARD-OVERPAYMENT',
        ])->assertCreated()
            ->assertJsonPath('payment_mode', 'overpayment')
            ->assertJsonPath('allocated_amount', '500.0000')
            ->assertJsonPath('total_allocated_amount', '700.0000')
            ->assertJsonPath('overpayment_amount', '200.0000')
            ->assertJsonPath('carried_forward_amount', '200.0000')
            ->assertJsonPath('unapplied_credit_amount', '0.0000')
            ->assertJsonPath('amount_due_after', '0.0000');
        $this->assertDatabaseHas('payment_obligations', ['id' => $secondStatement->id, 'status' => 'paid']);
        $this->assertDatabaseHas('payment_obligations', ['id' => $thirdStatement->id, 'status' => 'partially_paid']);
        $this->assertDatabaseHas('payment_allocations', [
            'payment_obligation_id' => $thirdStatement->id, 'amount' => 200,
        ]);
        $this->assertSame('3300.0000', $bank->fresh()->current_balance);
        $this->assertSame('100.0000', $card->fresh()->current_balance);
        $this->assertSame('9900.0000', $card->fresh()->available_balance);

        $this->getJson("/api/credit-cards/{$card->id}?as_of=2026-10-12")
            ->assertOk()
            ->assertJsonPath('outstanding_balance', '100.0000')
            ->assertJsonPath('available_credit', '9900.0000')
            ->assertJsonPath('amount_due', '100.0000')
            ->assertJsonPath('statement_history.0.payments.0.status', 'posted');
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $cardLedger->id, 'debit' => 700, 'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $bankLedger->id, 'debit' => 0, 'credit' => 700,
        ]);

        $this->postJson("/api/credit-cards/{$card->id}/payments", $basePayload + [
            'statement_id' => $thirdStatement->id,
            'amount' => 60000,
            'idempotency_key' => '09e24c76-97ae-45d5-969d-93a7688d3610',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Funding account '.$bank->id.' (Operating Bank) has PHP 3300.0000 available, but PHP 60000.0000 is required. Reconcile the funding account or select another account. Shortfall: PHP 56700.0000.')
            ->assertJsonPath('errors.funding_account_id.0', 'Reconcile this account at /api/financial-accounts/'.$bank->id.'/adjustments before retrying the payment.');
    }

    private function statement(
        FinancialAccount $card,
        Branch $branch,
        string $number,
        string $statementDate,
        string $dueDate,
        float $amount,
    ): PaymentObligation {
        return PaymentObligation::create([
            'number' => $number, 'type' => 'credit_card_statement',
            'financial_account_id' => $card->id, 'branch_id' => $branch->id,
            'offset_account_id' => $card->chart_account_id,
            'statement_date' => $statementDate, 'due_date' => $dueDate,
            'currency' => 'PHP', 'principal_amount' => $amount, 'status' => 'open',
        ]);
    }

    private function branch(): Branch
    {
        $inventory = ChartAccount::create(['code' => 'B1-INV', 'name' => 'Inventory', 'type' => 'asset']);
        $dueFrom = ChartAccount::create(['code' => 'B1-DF', 'name' => 'Due From', 'type' => 'asset']);
        $dueTo = ChartAccount::create(['code' => 'B1-DT', 'name' => 'Due To', 'type' => 'liability']);

        return Branch::create([
            'code' => 'B1', 'name' => 'Main Branch', 'inventory_account_id' => $inventory->id,
            'due_from_account_id' => $dueFrom->id, 'due_to_account_id' => $dueTo->id,
        ]);
    }
}
