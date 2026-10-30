<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardCashFlowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_cash_flow_totals_and_gap_filled_daily_points(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $cash = $this->financialAccount('Cash', 'cash', '1010', 'asset');
        $bank = $this->financialAccount('Bank', 'bank_account', '1020', 'asset');
        $card = $this->financialAccount('Card', 'credit_card', '2010', 'liability');
        $revenue = ChartAccount::create(['code' => '4010', 'name' => 'Revenue', 'type' => 'revenue']);
        $expense = ChartAccount::create(['code' => '5010', 'name' => 'Expense', 'type' => 'expense']);

        $this->entry('BEFORE', '2026-09-30', [
            [$cash->chart_account_id, 999, 0],
            [$revenue->id, 0, 999],
        ]);
        $this->entry('MONEY-IN', '2026-10-01', [
            [$cash->chart_account_id, 1000, 0],
            [$revenue->id, 0, 1000],
        ]);
        $this->entry('MONEY-OUT', '2026-10-02', [
            [$expense->id, 300, 0],
            [$cash->chart_account_id, 0, 300],
        ]);
        $this->entry('INTERNAL-TRANSFER', '2026-10-03', [
            [$bank->chart_account_id, 200, 0],
            [$cash->chart_account_id, 0, 200],
        ]);
        $this->entry('CARD-PURCHASE', '2026-10-04', [
            [$expense->id, 500, 0],
            [$card->chart_account_id, 0, 500],
        ]);

        $this->getJson('/api/dashboard/cash-flow?day_from=2026-10-01&day_to=2026-10-04')
            ->assertOk()
            ->assertJsonPath('money_in', '1000.0000')
            ->assertJsonPath('money_out', '300.0000')
            ->assertJsonPath('net_cash_flow', '700.0000')
            ->assertJsonPath('day_from', '2026-10-01')
            ->assertJsonPath('day_to', '2026-10-04')
            ->assertJsonPath('point_count', 4)
            ->assertJsonPath('points.0', [
                'date' => '2026-10-01',
                'money_in' => '1000.0000',
                'money_out' => '0.0000',
                'net_cash_flow' => '1000.0000',
                'cumulative_net_cash_flow' => '1000.0000',
            ])
            ->assertJsonPath('points.1', [
                'date' => '2026-10-02',
                'money_in' => '0.0000',
                'money_out' => '300.0000',
                'net_cash_flow' => '-300.0000',
                'cumulative_net_cash_flow' => '700.0000',
            ])
            ->assertJsonPath('points.2.net_cash_flow', '0.0000')
            ->assertJsonPath('points.3.net_cash_flow', '0.0000');
    }

    public function test_a_single_supplied_date_returns_one_chart_point(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard/cash-flow?day_to=2026-10-04')
            ->assertOk()
            ->assertJsonPath('day_from', '2026-10-04')
            ->assertJsonPath('day_to', '2026-10-04')
            ->assertJsonPath('point_count', 1)
            ->assertJsonPath('points.0.net_cash_flow', '0.0000');
    }

    public function test_it_validates_the_cash_flow_date_range(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard/cash-flow?day_from=2026-10-20&day_to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_to');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/cash-flow')->assertUnauthorized();
    }

    private function financialAccount(string $name, string $kind, string $code, string $type): FinancialAccount
    {
        $ledger = ChartAccount::create(['code' => $code, 'name' => $name, 'type' => $type]);

        return FinancialAccount::create([
            'name' => $name,
            'kind' => $kind,
            'currency' => 'PHP',
            'chart_account_id' => $ledger->id,
            'is_company_wide' => true,
            'credit_limit' => $kind === 'credit_card' ? 10000 : null,
        ]);
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
