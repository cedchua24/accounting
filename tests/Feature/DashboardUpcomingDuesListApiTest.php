<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Counterparty;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\PaymentObligation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardUpcomingDuesListApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_frontend_ready_upcoming_due_items(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$branch, $cash, $expense] = $this->accountingReferences();
        $card = FinancialAccount::create([
            'name' => 'RCBC Credit Card',
            'kind' => 'credit_card',
            'currency' => 'PHP',
            'chart_account_id' => ChartAccount::create([
                'code' => '2010', 'name' => 'Credit card liability', 'type' => 'liability',
            ])->id,
            'credit_limit' => 200000,
            'is_company_wide' => true,
        ]);
        $supplier = Counterparty::create([
            'type' => 'supplier', 'code' => 'SUP-1', 'name' => 'Flour & Co.',
        ]);

        $cardStatement = PaymentObligation::create([
            'branch_id' => $branch->id,
            'number' => 'CC-2026-10',
            'type' => 'credit_card_statement',
            'financial_account_id' => $card->id,
            'statement_date' => '2026-10-01',
            'due_date' => '2026-10-14',
            'currency' => 'PHP',
            'principal_amount' => 200000,
        ]);
        PaymentObligation::create([
            'branch_id' => $branch->id,
            'number' => 'OBL-PO-10879',
            'type' => 'purchase_order',
            'counterparty_id' => $supplier->id,
            'source_reference' => 'PO-10879',
            'due_date' => '2026-10-18',
            'currency' => 'PHP',
            'principal_amount' => 64800,
        ]);
        PaymentObligation::create([
            'branch_id' => $branch->id,
            'number' => 'OUTSIDE',
            'type' => 'expense',
            'due_date' => '2026-11-01',
            'currency' => 'PHP',
            'principal_amount' => 9999,
        ]);

        $payment = Payment::create([
            'branch_id' => $branch->id,
            'offset_account_id' => $expense->id,
            'number' => 'PAY-CARD-PARTIAL',
            'type' => 'chart_account',
            'direction' => 'outflow',
            'status' => 'posted',
            'payment_date' => '2026-10-10',
            'currency' => 'PHP',
            'amount' => 13579.50,
        ]);
        $payment->allocations()->create([
            'payment_obligation_id' => $cardStatement->id,
            'amount' => 13579.50,
        ]);

        $this->getJson('/api/dashboard/upcoming-dues-list?day_from=2026-10-11&day_to=2026-10-25&as_of=2026-10-11&limit=3')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('total_amount_due', '251220.5000')
            ->assertJsonPath('data.0.vendor', 'RCBC Credit Card')
            ->assertJsonPath('data.0.ref', 'Statement • Oct 2026')
            ->assertJsonPath('data.0.due', 'Oct 14')
            ->assertJsonPath('data.0.amount', '186420.5000')
            ->assertJsonPath('data.0.urgency', 'Due in 3 days')
            ->assertJsonPath('data.0.due_day', '14')
            ->assertJsonPath('data.0.due_month', 'OCT')
            ->assertJsonPath('data.0.days_until_due', 3)
            ->assertJsonPath('data.0.is_overdue', false)
            ->assertJsonPath('data.1.vendor', 'Flour & Co.')
            ->assertJsonPath('data.1.ref', 'PO-10879')
            ->assertJsonPath('data.1.urgency', 'Due in 7 days');
    }

    public function test_it_limits_display_items_but_keeps_full_summary(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$branch] = $this->accountingReferences();

        foreach ([12, 13, 14] as $day) {
            PaymentObligation::create([
                'branch_id' => $branch->id,
                'number' => "DUE-{$day}",
                'type' => 'other',
                'due_date' => "2026-10-{$day}",
                'currency' => 'PHP',
                'principal_amount' => 100,
            ]);
        }

        $this->getJson('/api/dashboard/upcoming-dues-list?day_from=2026-10-11&day_to=2026-10-25&as_of=2026-10-11&limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('total_amount_due', '300.0000');
    }

    public function test_it_validates_filters_and_requires_authentication(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/dashboard/upcoming-dues-list?limit=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        auth()->forgetGuards();
        $this->getJson('/api/dashboard/upcoming-dues-list')->assertUnauthorized();
    }

    private function accountingReferences(): array
    {
        $cash = ChartAccount::create(['code' => '1010', 'name' => 'Cash', 'type' => 'asset']);
        $expense = ChartAccount::create(['code' => '5010', 'name' => 'Expense', 'type' => 'expense']);
        $branch = Branch::create([
            'code' => 'MAIN',
            'name' => 'Main',
            'inventory_account_id' => $cash->id,
            'due_from_account_id' => $cash->id,
            'due_to_account_id' => $cash->id,
        ]);

        return [$branch, $cash, $expense];
    }
}
