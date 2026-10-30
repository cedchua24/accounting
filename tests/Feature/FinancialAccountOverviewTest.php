<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialAccountOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_current_savings_and_credit_card_limits(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $bankLedger = ChartAccount::create(['code' => '1010', 'name' => 'Bank', 'type' => 'asset']);
        $walletLedger = ChartAccount::create(['code' => '1020', 'name' => 'E-wallet', 'type' => 'asset']);
        $cardLedger = ChartAccount::create(['code' => '2010', 'name' => 'Credit card', 'type' => 'liability']);
        $equity = ChartAccount::create(['code' => '3010', 'name' => 'Opening equity', 'type' => 'equity']);
        $expense = ChartAccount::create(['code' => '5010', 'name' => 'Purchases', 'type' => 'expense']);

        $bank = FinancialAccount::create([
            'name' => 'BDO Savings', 'kind' => 'bank_account', 'currency' => 'PHP',
            'chart_account_id' => $bankLedger->id, 'is_company_wide' => true,
        ]);
        $wallet = FinancialAccount::create([
            'name' => 'GCash', 'kind' => 'e_wallet', 'currency' => 'PHP',
            'chart_account_id' => $walletLedger->id, 'is_company_wide' => true,
        ]);
        $card = FinancialAccount::create([
            'name' => 'RCBC Credit Card', 'kind' => 'credit_card', 'currency' => 'PHP',
            'chart_account_id' => $cardLedger->id, 'is_company_wide' => true,
            'credit_limit' => 200000,
        ]);

        $entry = JournalEntry::create([
            'kind' => 'payment', 'number' => 'OPENING-BALANCES', 'entry_date' => '2026-10-12',
        ]);
        $entry->lines()->createMany([
            ['chart_account_id' => $bankLedger->id, 'debit' => 1834250.40, 'credit' => 0],
            ['chart_account_id' => $walletLedger->id, 'debit' => 248650.75, 'credit' => 0],
            ['chart_account_id' => $cardLedger->id, 'debit' => 0, 'credit' => 186420.50],
            ['chart_account_id' => $equity->id, 'debit' => 0, 'credit' => 2082901.15],
            ['chart_account_id' => $expense->id, 'debit' => 186420.50, 'credit' => 0],
        ]);

        $response = $this->getJson('/api/financial-accounts')->assertOk();
        $accounts = collect($response->json('data'))->keyBy('id');

        $this->assertSame('1834250.4000', $accounts[$bank->id]['current_balance']);
        $this->assertNull($accounts[$bank->id]['outstanding_balance']);
        $this->assertSame('248650.7500', $accounts[$wallet->id]['current_balance']);
        $this->assertSame('200000.0000', $accounts[$card->id]['credit_limit']);
        $this->assertSame('186420.5000', $accounts[$card->id]['current_balance']);
        $this->assertSame('186420.5000', $accounts[$card->id]['outstanding_balance']);
        $this->assertSame('13579.5000', $accounts[$card->id]['available_balance']);
        $this->assertSame('13579.5000', $accounts[$card->id]['available_credit']);
        $this->assertNotNull($accounts[$card->id]['balance_updated_at']);
    }

    public function test_index_can_filter_accounts_by_payment_method(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $accounts = collect([
            'cash' => ['cash', 'Cash drawer'],
            'bank_account' => ['bank_account', 'BDO Savings'],
            'checking_account' => ['checking_account', 'BDO Checking'],
            'e_wallet' => ['e_wallet', 'GCash'],
            'credit_card' => ['credit_card', 'RCBC Credit Card'],
        ])->mapWithKeys(function (array $definition, string $key) {
            $ledger = ChartAccount::create([
                'code' => 'LEDGER-'.$key,
                'name' => $definition[1],
                'type' => $key === 'credit_card' ? 'liability' : 'asset',
            ]);

            return [$key => FinancialAccount::create([
                'name' => $definition[1], 'kind' => $definition[0], 'currency' => 'PHP',
                'chart_account_id' => $ledger->id, 'is_company_wide' => true,
            ])];
        });

        $bankTransfer = PaymentMethod::create([
            'code' => 'BANK', 'name' => 'Bank transfer', 'type' => 'bank_transfer',
        ]);
        $wallet = PaymentMethod::create([
            'code' => 'WALLET', 'name' => 'E-wallet', 'type' => 'e_wallet',
        ]);
        $card = PaymentMethod::create([
            'code' => 'CARD', 'name' => 'Credit card', 'type' => 'credit_card',
        ]);

        $this->getJson("/api/financial-accounts?payment_method_id={$bankTransfer->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $accounts['bank_account']->id])
            ->assertJsonFragment(['id' => $accounts['checking_account']->id])
            ->assertJsonMissing(['id' => $accounts['e_wallet']->id]);

        $this->getJson("/api/financial-accounts?payment_method_id={$wallet->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $accounts['e_wallet']->id);

        $this->getJson("/api/financial-accounts?payment_method_id={$card->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $accounts['credit_card']->id);
    }

    public function test_cached_balances_can_be_rebuilt_from_the_journal(): void
    {
        $bankLedger = ChartAccount::create(['code' => '1030', 'name' => 'Rebuild Bank', 'type' => 'asset']);
        $equity = ChartAccount::create(['code' => '3030', 'name' => 'Rebuild Equity', 'type' => 'equity']);
        $bank = FinancialAccount::create([
            'name' => 'Rebuild Bank', 'kind' => 'bank_account', 'currency' => 'PHP',
            'chart_account_id' => $bankLedger->id, 'is_company_wide' => true,
        ]);
        $entry = JournalEntry::create([
            'kind' => 'payment', 'number' => 'REBUILD-TEST', 'entry_date' => '2026-10-12',
        ]);
        $entry->lines()->createMany([
            ['chart_account_id' => $bankLedger->id, 'debit' => 1250, 'credit' => 0],
            ['chart_account_id' => $equity->id, 'debit' => 0, 'credit' => 1250],
        ]);

        DB::table('financial_accounts')->where('id', $bank->id)->update([
            'current_balance' => 99,
            'available_balance' => 99,
        ]);

        $this->artisan('accounting:rebuild-financial-account-balances')
            ->expectsOutput('Rebuilt balances for 1 financial accounts.')
            ->assertSuccessful();

        $bank->refresh();
        $this->assertSame('1250.0000', $bank->current_balance);
        $this->assertSame('1250.0000', $bank->available_balance);
        $this->assertNotNull($bank->balance_updated_at);
    }
}
