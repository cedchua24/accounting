<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialAccountTransferListApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_transfers_by_dates_status_and_either_side_of_a_bank_account(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $bankA = $this->account('Bank A', '1010');
        $bankB = $this->account('Bank B', '1020');
        $bankC = $this->account('Bank C', '1030');

        $this->transfer($bankA, $bankB, '2026-10-01', 'posted', 'TRF-001');
        $expected = $this->transfer($bankB, $bankA, '2026-10-10', 'voided', 'TRF-002');
        $this->transfer($bankC, $bankA, '2026-10-20', 'posted', 'TRF-003');

        $this->getJson("/api/account-transfers?date_from=2026-10-05&date_to=2026-10-15&status=void&financial_account_id={$bankA->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $expected->id)
            ->assertJsonPath('data.0.status', 'voided')
            ->assertJsonPath('data.0.source_account.name', 'Bank B')
            ->assertJsonPath('data.0.destination_account.name', 'Bank A');
    }

    public function test_bank_id_and_legacy_date_aliases_remain_supported(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $bankA = $this->account('Bank A', '1010');
        $bankB = $this->account('Bank B', '1020');
        $expected = $this->transfer($bankA, $bankB, '2026-10-10', 'posted', 'TRF-001');
        $this->transfer($bankA, $bankB, '2026-10-20', 'posted', 'TRF-002');

        $this->getJson("/api/account-transfers?from=2026-10-01&to=2026-10-15&status=posted&bank_id={$bankB->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $expected->id);

        $this->getJson('/api/account-transfers?date_from=2026-10-15')
            ->assertOk()
            ->assertJsonPath('total', 1);
        $this->getJson('/api/account-transfers?date_to=2026-10-15')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_it_validates_transfer_list_filters(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/account-transfers?date_from=2026-10-20&date_to=2026-10-01&status=cancelled&financial_account_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to', 'status', 'financial_account_id']);
    }

    private function account(string $name, string $code): FinancialAccount
    {
        $ledger = ChartAccount::create([
            'code' => $code,
            'name' => $name,
            'type' => 'asset',
        ]);

        return FinancialAccount::create([
            'name' => $name,
            'kind' => 'bank_account',
            'currency' => 'PHP',
            'chart_account_id' => $ledger->id,
            'is_company_wide' => true,
            'usable_for_receipts' => true,
            'usable_for_payments' => true,
        ]);
    }

    private function transfer(
        FinancialAccount $source,
        FinancialAccount $destination,
        string $date,
        string $status,
        string $number
    ): FinancialAccountTransfer {
        return FinancialAccountTransfer::create([
            'number' => $number,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'destination_type' => 'internal',
            'transfer_method' => 'online',
            'transfer_date' => $date,
            'currency' => 'PHP',
            'amount' => 100,
            'status' => $status,
        ]);
    }
}
