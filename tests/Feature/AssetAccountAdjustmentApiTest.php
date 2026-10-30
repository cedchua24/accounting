<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetAccountAdjustmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_bank_checking_and_wallet_balances_can_be_reconciled_with_full_audit_details(): void
    {
        $user = User::factory()->create(['name' => 'Reconciliation User']);
        Sanctum::actingAs($user);
        $equity = ChartAccount::create(['code' => '3000', 'name' => 'Opening Equity', 'type' => 'equity']);
        $differenceAccount = ChartAccount::create([
            'code' => '4900', 'name' => 'Account Reconciliation Difference', 'type' => 'revenue',
        ]);
        $accounts = collect([
            ['kind' => 'bank_account', 'code' => '1010', 'name' => 'BDO Savings'],
            ['kind' => 'checking_account', 'code' => '1020', 'name' => 'BPI Checking'],
            ['kind' => 'cash', 'code' => '1030', 'name' => 'Main Cash'],
            ['kind' => 'e_wallet', 'code' => '1040', 'name' => 'GCash Online Transfer'],
        ])->map(function (array $definition) use ($equity, $user) {
            $ledger = ChartAccount::create([
                'code' => $definition['code'], 'name' => $definition['name'], 'type' => 'asset',
            ]);
            $account = FinancialAccount::create([
                'name' => $definition['name'], 'kind' => $definition['kind'], 'currency' => 'PHP',
                'chart_account_id' => $ledger->id, 'is_company_wide' => true,
                'usable_for_receipts' => true, 'usable_for_payments' => true,
            ]);
            $account->applications()->create([
                'application' => $definition['kind'] === 'cash' ? 'customer_receipt' : 'expense_payment',
                'source' => 'manual',
            ]);
            $opening = JournalEntry::create([
                'kind' => 'payment', 'number' => 'OPEN-'.$definition['code'],
                'entry_date' => '2026-10-12', 'memo' => 'Opening balance', 'created_by' => $user->id,
            ]);
            $opening->lines()->createMany([
                ['chart_account_id' => $ledger->id, 'debit' => 100000, 'credit' => 0],
                ['chart_account_id' => $equity->id, 'debit' => 0, 'credit' => 100000],
            ]);

            return $account;
        });

        foreach ($accounts as $index => $account) {
            $response = $this->postJson("/api/financial-accounts/{$account->id}/adjustments", [
                'adjustment_account_id' => $differenceAccount->id,
                'actual_balance' => 101000 + $index,
                'adjustment_date' => '2026-10-13',
                'reference' => 'REAL-BALANCE-'.$account->id,
                'reason' => 'Actual account balance does not tally with the system.',
            ])->assertCreated()
                ->assertJsonPath('status', 'posted')
                ->assertJsonPath('system_balance_before', '100000.0000')
                ->assertJsonPath('actual_balance', number_format(101000 + $index, 4, '.', ''))
                ->assertJsonPath('adjustment_amount', number_format(1000 + $index, 4, '.', ''))
                ->assertJsonPath('direction', 'INCREASE')
                ->assertJsonPath('financial_account.id', $account->id)
                ->assertJsonPath('adjustment_account.id', $differenceAccount->id)
                ->assertJsonPath('audit.created_by.id', $user->id)
                ->assertJsonPath('audit.posted_by.id', $user->id)
                ->assertJsonPath('journal_transactions.0.kind', 'account_balance_adjustment');

            if ($index === 0) {
                $bankAdjustment = $response->json();
            }
        }

        $bank = $accounts->first();
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $bank->chart_account_id, 'debit' => 1000, 'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $differenceAccount->id, 'debit' => 0, 'credit' => 1000,
        ]);

        $this->getJson("/api/financial-accounts/{$bank->id}/adjustments")
            ->assertOk()
            ->assertJsonPath('data.0.id', $bankAdjustment['id'])
            ->assertJsonPath('data.0.reference', 'REAL-BALANCE-'.$bank->id);

        $this->getJson("/api/financial-accounts/{$bank->id}/adjustments/{$bankAdjustment['id']}")
            ->assertOk()
            ->assertJsonPath('journal_transactions.0.lines.0.debit', '1000.0000');

        $this->getJson("/api/financial-accounts/{$bank->id}/balance")
            ->assertOk()
            ->assertJsonPath('balance', '101000.0000');

        $this->postJson("/api/financial-accounts/{$bank->id}/adjustments/{$bankAdjustment['id']}/void", [
            'reason' => 'The bank corrected its posted balance.',
        ])->assertOk()
            ->assertJsonPath('status', 'voided')
            ->assertJsonPath('audit.voided_by.id', $user->id)
            ->assertJsonPath('journal_transactions.1.kind', 'account_balance_adjustment_reversal');

        $this->getJson("/api/financial-accounts/{$bank->id}/balance")
            ->assertOk()
            ->assertJsonPath('balance', '100000.0000');
    }

    public function test_credit_cards_cannot_use_the_asset_balance_adjustment_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cardLedger = ChartAccount::create(['code' => '2100', 'name' => 'Card Payable', 'type' => 'liability']);
        $difference = ChartAccount::create(['code' => '6100', 'name' => 'Difference', 'type' => 'expense']);
        $card = FinancialAccount::create([
            'name' => 'Credit Card', 'kind' => 'credit_card', 'currency' => 'PHP',
            'chart_account_id' => $cardLedger->id, 'credit_limit' => 100000,
            'statement_day' => 1, 'due_day' => 20, 'is_company_wide' => true,
        ]);

        $this->postJson("/api/financial-accounts/{$card->id}/adjustments", [
            'adjustment_account_id' => $difference->id,
            'actual_balance' => 1000,
            'adjustment_date' => '2026-10-13',
            'reason' => 'Wrong endpoint',
        ])->assertNotFound();
    }
}
