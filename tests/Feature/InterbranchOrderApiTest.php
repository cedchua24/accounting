<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InterbranchOrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_clearing_posts_balanced_entries_without_a_bank_payment(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $source = $this->branch('SRC', 'Source');
        $destination = $this->branch('DST', 'Destination');

        $order = $this->postJson('/api/interbranch-orders', [
            'number' => 'IBO-0001',
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'order_date' => '2026-10-04',
            'currency' => 'PHP',
            'amount' => 10000,
            'settlement_mode' => 'internal_clearing',
            'memo' => 'Internal stock replenishment',
        ])->assertCreated()->assertJsonPath('status', 'draft')->json();

        $this->postJson("/api/interbranch-orders/{$order['id']}/post")
            ->assertOk()->assertJsonPath('status', 'posted');

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 4);

        foreach ([$source->id, $destination->id] as $branchId) {
            $totals = DB::table('journal_lines')->where('branch_id', $branchId)
                ->selectRaw('SUM(debit) debit, SUM(credit) credit')->first();
            $this->assertEquals($totals->debit, $totals->credit);
        }

        $this->postJson("/api/interbranch-orders/{$order['id']}/void", ['reason' => 'Transfer cancelled'])
            ->assertOk()->assertJsonPath('status', 'voided');
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('journal_lines', 8);
    }

    private function branch(string $prefix, string $name): Branch
    {
        $inventory = ChartAccount::create(['code' => $prefix.'-INV', 'name' => $name.' Inventory', 'type' => 'asset']);
        $dueFrom = ChartAccount::create(['code' => $prefix.'-DF', 'name' => $name.' Due From', 'type' => 'asset']);
        $dueTo = ChartAccount::create(['code' => $prefix.'-DT', 'name' => $name.' Due To', 'type' => 'liability']);

        return Branch::create([
            'code' => $prefix, 'name' => $name, 'inventory_account_id' => $inventory->id,
            'due_from_account_id' => $dueFrom->id, 'due_to_account_id' => $dueTo->id,
        ]);
    }
}
