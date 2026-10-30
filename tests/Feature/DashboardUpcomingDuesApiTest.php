<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Payment;
use App\Models\PaymentObligation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardUpcomingDuesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_unpaid_obligation_balances_due_in_the_selected_period(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$branch, $offset] = $this->accountingReferences();

        $first = $this->obligation($branch, 'DUE-1', 'open', '2026-10-20', 1000, 100, 50, 20);
        $partial = $this->obligation($branch, 'DUE-2', 'partially_paid', '2026-10-25', 500);
        $this->obligation($branch, 'PAID', 'paid', '2026-10-22', 9000);
        $this->obligation($branch, 'VOIDED', 'voided', '2026-10-23', 9000);
        $this->obligation($branch, 'AFTER', 'open', '2026-11-01', 9000);

        $payment = Payment::create([
            'branch_id' => $branch->id,
            'offset_account_id' => $offset->id,
            'number' => 'PAY-PARTIAL',
            'type' => 'chart_account',
            'direction' => 'outflow',
            'status' => 'posted',
            'payment_date' => '2026-10-15',
            'currency' => 'PHP',
            'amount' => 200,
        ]);
        $payment->allocations()->create([
            'payment_obligation_id' => $partial->id,
            'amount' => 200,
        ]);

        $this->getJson('/api/dashboard/upcoming-dues?day_from=2026-10-19&day_to=2026-10-31')
            ->assertOk()
            ->assertExactJson([
                'total_upcoming_dues' => '1470.0000',
                'obligation_count' => 2,
                'nearest_due_date' => $first->due_date->toDateString(),
                'day_from' => '2026-10-19',
                'day_to' => '2026-10-31',
            ]);
    }

    public function test_it_ignores_draft_and_voided_allocations_when_calculating_balance_due(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$branch, $offset] = $this->accountingReferences();
        $obligation = $this->obligation($branch, 'DUE-1', 'open', '2026-10-20', 500);

        foreach (['draft', 'voided'] as $status) {
            $payment = Payment::create([
                'branch_id' => $branch->id,
                'offset_account_id' => $offset->id,
                'number' => 'PAY-'.strtoupper($status),
                'type' => 'chart_account',
                'direction' => 'outflow',
                'status' => $status,
                'payment_date' => '2026-10-15',
                'currency' => 'PHP',
                'amount' => 100,
            ]);
            $payment->allocations()->create([
                'payment_obligation_id' => $obligation->id,
                'amount' => 100,
            ]);
        }

        $this->getJson('/api/dashboard/upcoming-dues')
            ->assertOk()
            ->assertJsonPath('total_upcoming_dues', '500.0000')
            ->assertJsonPath('obligation_count', 1);
    }

    public function test_it_validates_the_due_date_range(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard/upcoming-dues?day_from=2026-10-20&day_to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_to');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/upcoming-dues')->assertUnauthorized();
    }

    private function accountingReferences(): array
    {
        $asset = ChartAccount::create(['code' => '1010', 'name' => 'Cash', 'type' => 'asset']);
        $offset = ChartAccount::create(['code' => '5010', 'name' => 'Expense', 'type' => 'expense']);
        $branch = Branch::create([
            'code' => 'MAIN',
            'name' => 'Main',
            'inventory_account_id' => $asset->id,
            'due_from_account_id' => $asset->id,
            'due_to_account_id' => $asset->id,
        ]);

        return [$branch, $offset];
    }

    private function obligation(
        Branch $branch,
        string $number,
        string $status,
        string $dueDate,
        int $principal,
        int $interest = 0,
        int $fee = 0,
        int $adjustment = 0
    ): PaymentObligation {
        return PaymentObligation::create([
            'branch_id' => $branch->id,
            'number' => $number,
            'type' => 'other',
            'due_date' => $dueDate,
            'currency' => 'PHP',
            'principal_amount' => $principal,
            'interest_amount' => $interest,
            'fee_amount' => $fee,
            'adjustment_amount' => $adjustment,
            'status' => $status,
        ]);
    }
}
