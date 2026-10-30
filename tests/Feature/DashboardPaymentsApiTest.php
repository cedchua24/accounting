<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardPaymentsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_posted_outgoing_payments_for_the_selected_period(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$branch, $offset] = $this->accountingReferences();

        $this->payment($branch, $offset, 'PREVIOUS-1', 'expense', 'outflow', 'posted', '2026-09-10', 1000);
        $this->payment($branch, $offset, 'CURRENT-PO', 'purchase_order', 'outflow', 'posted', '2026-10-01', 600);
        $this->payment($branch, $offset, 'CURRENT-EXPENSE', 'expense', 'outflow', 'posted', '2026-10-31', 650);
        $this->payment($branch, $offset, 'DRAFT', 'expense', 'outflow', 'draft', '2026-10-15', 9000);
        $this->payment($branch, $offset, 'VOIDED', 'chart_account', 'outflow', 'voided', '2026-10-16', 9000);
        $this->payment($branch, $offset, 'COLLECTION', 'customer', 'inflow', 'posted', '2026-10-17', 9000);
        $this->payment($branch, $offset, 'AFTER', 'expense', 'outflow', 'posted', '2026-11-01', 9000);

        $this->getJson('/api/dashboard/payments?day_from=2026-10-01&day_to=2026-10-31')
            ->assertOk()
            ->assertExactJson([
                'total_payments' => '1250.0000',
                'payment_count' => 2,
                'previous_period_payments' => '1000.0000',
                'previous_period_payment_count' => 1,
                'net_change' => '250.0000',
                'change_percentage' => '25.00',
                'day_from' => '2026-10-01',
                'day_to' => '2026-10-31',
                'previous_day_from' => '2026-08-31',
                'previous_day_to' => '2026-09-30',
            ]);
    }

    public function test_it_returns_all_posted_outgoing_payments_without_dates(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$branch, $offset] = $this->accountingReferences();

        $this->payment($branch, $offset, 'PAYMENT-1', 'expense', 'outflow', 'posted', '2026-09-10', 100);
        $this->payment($branch, $offset, 'PAYMENT-2', 'chart_account', 'outflow', 'posted', '2026-10-10', 250);

        $this->getJson('/api/dashboard/payments')
            ->assertOk()
            ->assertJsonPath('total_payments', '350.0000')
            ->assertJsonPath('payment_count', 2)
            ->assertJsonPath('previous_period_payments', null)
            ->assertJsonPath('change_percentage', null);
    }

    public function test_it_validates_the_payment_date_range(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard/payments?day_from=2026-10-20&day_to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_to');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/payments')->assertUnauthorized();
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

    private function payment(
        Branch $branch,
        ChartAccount $offset,
        string $number,
        string $type,
        string $direction,
        string $status,
        string $date,
        int $amount
    ): void {
        Payment::create([
            'branch_id' => $branch->id,
            'offset_account_id' => $offset->id,
            'number' => $number,
            'type' => $type,
            'direction' => $direction,
            'status' => $status,
            'payment_date' => $date,
            'currency' => 'PHP',
            'amount' => $amount,
        ]);
    }
}
