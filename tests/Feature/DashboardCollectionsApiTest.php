<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardCollectionsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_posted_customer_collections_for_the_selected_period(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$branch, $offset] = $this->accountingReferences();

        $this->payment($branch, $offset, 'PREVIOUS-1', 'customer', 'inflow', 'posted', '2026-09-10', 1000);
        $this->payment($branch, $offset, 'CURRENT-1', 'customer', 'inflow', 'posted', '2026-10-01', 600);
        $this->payment($branch, $offset, 'CURRENT-2', 'customer', 'inflow', 'posted', '2026-10-31', 650);
        $this->payment($branch, $offset, 'DRAFT', 'customer', 'inflow', 'draft', '2026-10-15', 9000);
        $this->payment($branch, $offset, 'VOIDED', 'customer', 'inflow', 'voided', '2026-10-16', 9000);
        $this->payment($branch, $offset, 'SUPPLIER', 'purchase_order', 'outflow', 'posted', '2026-10-17', 9000);
        $this->payment($branch, $offset, 'AFTER', 'customer', 'inflow', 'posted', '2026-11-01', 9000);

        $this->getJson('/api/dashboard/collections?day_from=2026-10-01&day_to=2026-10-31')
            ->assertOk()
            ->assertExactJson([
                'total_collections' => '1250.0000',
                'collection_count' => 2,
                'previous_period_collections' => '1000.0000',
                'previous_period_collection_count' => 1,
                'net_change' => '250.0000',
                'change_percentage' => '25.00',
                'day_from' => '2026-10-01',
                'day_to' => '2026-10-31',
                'previous_day_from' => '2026-08-31',
                'previous_day_to' => '2026-09-30',
            ]);
    }

    public function test_it_returns_all_posted_customer_collections_without_dates(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$branch, $offset] = $this->accountingReferences();

        $this->payment($branch, $offset, 'COLLECTION-1', 'customer', 'inflow', 'posted', '2026-09-10', 100);
        $this->payment($branch, $offset, 'COLLECTION-2', 'customer', 'inflow', 'posted', '2026-10-10', 250);

        $this->getJson('/api/dashboard/collections')
            ->assertOk()
            ->assertJsonPath('total_collections', '350.0000')
            ->assertJsonPath('collection_count', 2)
            ->assertJsonPath('previous_period_collections', null)
            ->assertJsonPath('change_percentage', null);
    }

    public function test_it_validates_the_collection_date_range(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard/collections?day_from=2026-10-20&day_to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_to');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/collections')->assertUnauthorized();
    }

    private function accountingReferences(): array
    {
        $asset = ChartAccount::create(['code' => '1010', 'name' => 'Cash', 'type' => 'asset']);
        $offset = ChartAccount::create(['code' => '4010', 'name' => 'Sales', 'type' => 'revenue']);
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
