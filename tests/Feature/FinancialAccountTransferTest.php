<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialAccountTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_moves_between_accounts_without_branch_or_payment_details(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $gcashLedger = ChartAccount::create(['code' => '1010', 'name' => 'GCash Ced', 'type' => 'asset']);
        $bdoLedger = ChartAccount::create(['code' => '1020', 'name' => 'BDO Ced', 'type' => 'asset']);
        $equity = ChartAccount::create(['code' => '3000', 'name' => 'Opening Equity', 'type' => 'equity']);
        $gcash = $this->account('GCash Ced', 'e_wallet', $gcashLedger);
        $bdo = $this->account('BDO Ced', 'bank_account', $bdoLedger);

        $opening = JournalEntry::create([
            'kind' => 'payment', 'number' => 'OPENING-BALANCES', 'entry_date' => '2026-10-04',
            'memo' => 'Opening balances', 'created_by' => $user->id,
        ]);
        $opening->lines()->createMany([
            ['chart_account_id' => $gcashLedger->id, 'debit' => 2000, 'credit' => 0],
            ['chart_account_id' => $bdoLedger->id, 'debit' => 1000, 'credit' => 0],
            ['chart_account_id' => $equity->id, 'debit' => 0, 'credit' => 3000],
        ]);

        $transfer = $this->postJson('/api/account-transfers', [
            'source_financial_account_id' => $gcash->id,
            'destination_financial_account_id' => $bdo->id,
            'transfer_date' => '2026-10-04',
            'currency' => 'PHP',
            'amount' => 2000,
            'reference' => 'GCASH-TO-BDO-001',
            'post' => true,
        ])->assertCreated()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('amount', '2000.0000')
            ->json();

        $this->assertArrayNotHasKey('branch_id', $transfer);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $gcashLedger->id, 'branch_id' => null, 'debit' => 0, 'credit' => 2000,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $bdoLedger->id, 'branch_id' => null, 'debit' => 2000, 'credit' => 0,
        ]);
        $this->getJson("/api/financial-accounts/{$gcash->id}/balance")
            ->assertOk()->assertJsonPath('balance', '0.0000');
        $this->getJson("/api/financial-accounts/{$bdo->id}/balance")
            ->assertOk()->assertJsonPath('balance', '3000.0000');
        $this->getJson("/api/financial-accounts/{$gcash->id}/movements")
            ->assertOk()
            ->assertJsonPath('data.0.movement_type', 'ACCOUNT_TRANSFER_SEND')
            ->assertJsonPath('data.0.direction', 'OUT_BOUND')
            ->assertJsonPath('data.0.amount', '2000.0000');
        $this->getJson("/api/financial-accounts/{$bdo->id}/movements")
            ->assertOk()
            ->assertJsonPath('data.0.movement_type', 'ACCOUNT_TRANSFER_RECEIVE')
            ->assertJsonPath('data.0.direction', 'IN_BOUND')
            ->assertJsonPath('data.0.amount', '2000.0000');

        $this->postJson('/api/account-transfers', [
            'source_financial_account_id' => $gcash->id,
            'destination_financial_account_id' => $bdo->id,
            'transfer_date' => '2026-10-04', 'currency' => 'PHP', 'amount' => 1, 'post' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->postJson("/api/account-transfers/{$transfer['id']}/void", ['reason' => 'Bank transfer reversed'])
            ->assertOk()->assertJsonPath('status', 'voided');
        $this->getJson("/api/financial-accounts/{$gcash->id}/balance")
            ->assertOk()->assertJsonPath('balance', '2000.0000');
        $this->getJson("/api/financial-accounts/{$bdo->id}/balance")
            ->assertOk()->assertJsonPath('balance', '1000.0000');
    }

    private function account(string $name, string $kind, ChartAccount $ledger): FinancialAccount
    {
        $account = FinancialAccount::create([
            'name' => $name, 'kind' => $kind, 'currency' => 'PHP',
            'chart_account_id' => $ledger->id, 'is_company_wide' => true,
            'usable_for_receipts' => true, 'usable_for_payments' => true,
        ]);
        $account->applications()->create([
            'application' => FinancialAccountApplication::ACCOUNT_TRANSFER,
            'source' => 'manual',
        ]);

        return $account;
    }
}
