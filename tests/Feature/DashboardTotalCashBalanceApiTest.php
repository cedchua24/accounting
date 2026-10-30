<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTotalCashBalanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_current_liquid_funds_and_excludes_credit_cards(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->account('Cash drawer', 'cash', '100.2500');
        $this->account('Savings', 'bank_account', '200.5000');
        $this->account('Checking', 'checking_account', '300.7500');
        $this->account('Wallet', 'e_wallet', '400.0000');
        $this->account('Credit card', 'credit_card', '9999.0000');

        $this->getJson('/api/dashboard/total-cash-balance')
            ->assertOk()
            ->assertExactJson([
                'total_cash_balance' => '1001.5000',
                'opening_cash_balance' => null,
                'net_change' => null,
                'change_percentage' => null,
                'day_from' => null,
                'day_to' => null,
                'account_count' => 4,
                'included_account_types' => ['cash', 'bank_account', 'checking_account', 'e_wallet'],
            ]);
    }

    public function test_it_returns_historical_closing_balance_and_change_for_a_date_range(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $cash = $this->account('Cash drawer', 'cash');
        $bank = $this->account('Savings', 'bank_account');
        $card = $this->account('Credit card', 'credit_card');

        $this->entry('OPENING', '2026-10-01', [
            [$cash->chart_account_id, 500, 0],
            [$bank->chart_account_id, 1000, 0],
            [$card->chart_account_id, 0, 5000],
        ]);
        $this->entry('RANGE-IN', '2026-10-10', [[$cash->chart_account_id, 300, 0]]);
        $this->entry('RANGE-OUT', '2026-10-20', [[$bank->chart_account_id, 0, 200]]);
        $this->entry('AFTER-RANGE', '2026-11-01', [[$bank->chart_account_id, 900, 0]]);

        $this->getJson('/api/dashboard/total-cash-balance?day_from=2026-10-05&day_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('total_cash_balance', '1600.0000')
            ->assertJsonPath('opening_cash_balance', '1500.0000')
            ->assertJsonPath('net_change', '100.0000')
            ->assertJsonPath('change_percentage', '6.66')
            ->assertJsonPath('day_from', '2026-10-05')
            ->assertJsonPath('day_to', '2026-10-31')
            ->assertJsonPath('account_count', 2);
    }

    public function test_it_validates_the_date_range(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard/total-cash-balance?day_from=2026-10-20&day_to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_to');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/total-cash-balance')->assertUnauthorized();
    }

    private function account(string $name, string $kind, string $currentBalance = '0.0000'): FinancialAccount
    {
        $ledger = ChartAccount::create([
            'code' => 'LEDGER-'.str()->slug($name),
            'name' => $name,
            'type' => $kind === 'credit_card' ? 'liability' : 'asset',
        ]);

        $account = FinancialAccount::create([
            'name' => $name,
            'kind' => $kind,
            'currency' => 'PHP',
            'chart_account_id' => $ledger->id,
            'is_company_wide' => true,
            'credit_limit' => $kind === 'credit_card' ? 20000 : null,
        ]);
        $account->forceFill(['current_balance' => $currentBalance])->save();

        return $account;
    }

    private function entry(string $number, string $date, array $lines): void
    {
        $entry = JournalEntry::create([
            'kind' => 'payment',
            'number' => $number,
            'entry_date' => $date,
        ]);

        foreach ($lines as [$chartAccountId, $debit, $credit]) {
            $entry->lines()->create([
                'chart_account_id' => $chartAccountId,
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }
    }
}
