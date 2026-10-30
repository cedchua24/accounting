<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExternalFinancialAccountTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_transfer_can_be_posted_to_an_external_recipient(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $bankLedger = $this->ledger('1010', 'Operating Bank', 'asset');
        $clearingLedger = $this->ledger('1090', 'External Transfer Clearing', 'asset');
        $equity = $this->ledger('3000', 'Opening Equity', 'equity');
        $bank = $this->account('Operating Bank', 'bank_account', $bankLedger);
        $this->openingBalance($user, $bankLedger, $equity, 5000);

        $response = $this->postJson('/api/account-transfers', [
            'source_financial_account_id' => $bank->id,
            'destination_type' => 'external',
            'transfer_method' => 'online',
            'offset_account_id' => $clearingLedger->id,
            'recipient_name' => 'Juan Dela Cruz',
            'recipient_type' => 'individual',
            'external_institution_name' => 'Outside Bank',
            'external_account_name' => 'Juan Dela Cruz',
            'external_account_number' => '1234 5678 9012',
            'recipient_contact' => 'juan@example.test',
            'transfer_date' => '2026-10-21',
            'currency' => 'php',
            'amount' => 1250,
            'reference' => 'EXT-ONLINE-001',
            'post' => true,
        ])->assertCreated()
            ->assertJsonPath('destination_type', 'external')
            ->assertJsonPath('transfer_method', 'online')
            ->assertJsonPath('destination_financial_account_id', null)
            ->assertJsonPath('masked_external_account_number', '•••• 9012')
            ->assertJsonMissingPath('external_account_number')
            ->assertJsonPath('status', 'posted');

        $transferId = $response->json('id');
        $storedAccountNumber = DB::table('financial_account_transfers')
            ->where('id', $transferId)
            ->value('external_account_number');

        $this->assertNotSame('123456789012', $storedAccountNumber);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $clearingLedger->id,
            'debit' => 1250,
            'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $bankLedger->id,
            'debit' => 0,
            'credit' => 1250,
        ]);
        $this->getJson("/api/financial-accounts/{$bank->id}/balance")
            ->assertOk()
            ->assertJsonPath('balance', '3750.0000');
    }

    public function test_cheque_transfer_requires_a_cheque_number_and_a_checking_account(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $bankLedger = $this->ledger('1010', 'Savings Bank', 'asset');
        $checkingLedger = $this->ledger('1020', 'Checking Bank', 'asset');
        $expenseLedger = $this->ledger('5010', 'Outside Services', 'expense');
        $equity = $this->ledger('3000', 'Opening Equity', 'equity');
        $bank = $this->account('Savings Bank', 'bank_account', $bankLedger);
        $checking = $this->account('Checking Bank', 'checking_account', $checkingLedger);
        $this->openingBalance($user, $bankLedger, $equity, 1000);
        $this->openingBalance($user, $checkingLedger, $equity, 1000, 'OPENING-CHECKING');

        $payload = [
            'destination_type' => 'external',
            'transfer_method' => 'cheque',
            'offset_account_id' => $expenseLedger->id,
            'recipient_name' => 'Outside Supplier',
            'recipient_type' => 'business',
            'transfer_date' => '2026-10-21',
            'currency' => 'PHP',
            'amount' => 500,
            'post' => true,
        ];

        $this->postJson('/api/account-transfers', [
            ...$payload,
            'source_financial_account_id' => $checking->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('cheque_number');

        $this->postJson('/api/account-transfers', [
            ...$payload,
            'source_financial_account_id' => $bank->id,
            'cheque_number' => 'CHK-0001',
        ])->assertUnprocessable()->assertJsonValidationErrors('transfer_method');

        $this->postJson('/api/account-transfers', [
            ...$payload,
            'source_financial_account_id' => $checking->id,
            'cheque_number' => 'CHK-0001',
        ])->assertCreated()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('cheque_number', 'CHK-0001');
    }

    public function test_cash_and_e_wallet_sources_can_send_to_external_recipients(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $cashLedger = $this->ledger('1010', 'Cash on Hand', 'asset');
        $walletLedger = $this->ledger('1020', 'Company Wallet', 'asset');
        $expenseLedger = $this->ledger('5010', 'Outside Services', 'expense');
        $equity = $this->ledger('3000', 'Opening Equity', 'equity');
        $cash = $this->account('Cash on Hand', 'cash', $cashLedger);
        $wallet = $this->account('Company Wallet', 'e_wallet', $walletLedger);
        $this->openingBalance($user, $cashLedger, $equity, 1000, 'OPENING-CASH');
        $this->openingBalance($user, $walletLedger, $equity, 1000, 'OPENING-WALLET');

        $this->postJson('/api/account-transfers', [
            'source_financial_account_id' => $cash->id,
            'destination_type' => 'external',
            'transfer_method' => 'cash',
            'offset_account_id' => $expenseLedger->id,
            'recipient_name' => 'Walk-in Recipient',
            'transfer_date' => '2026-10-21',
            'currency' => 'PHP',
            'amount' => 100,
            'post' => true,
        ])->assertCreated()->assertJsonPath('status', 'posted');

        $this->postJson('/api/account-transfers', [
            'source_financial_account_id' => $wallet->id,
            'destination_type' => 'external',
            'transfer_method' => 'e_wallet',
            'offset_account_id' => $expenseLedger->id,
            'recipient_name' => 'Wallet Recipient',
            'external_institution_name' => 'GCash',
            'external_account_number' => '09171234567',
            'transfer_date' => '2026-10-21',
            'currency' => 'PHP',
            'amount' => 200,
            'post' => true,
        ])->assertCreated()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('masked_external_account_number', '•••• 4567');
    }

    private function ledger(string $code, string $name, string $type): ChartAccount
    {
        return ChartAccount::create(compact('code', 'name', 'type'));
    }

    private function account(string $name, string $kind, ChartAccount $ledger): FinancialAccount
    {
        $account = FinancialAccount::create([
            'name' => $name,
            'kind' => $kind,
            'currency' => 'PHP',
            'chart_account_id' => $ledger->id,
            'is_company_wide' => true,
            'usable_for_receipts' => true,
            'usable_for_payments' => true,
        ]);
        $account->applications()->create([
            'application' => FinancialAccountApplication::ACCOUNT_TRANSFER,
            'source' => 'manual',
        ]);

        return $account;
    }

    private function openingBalance(
        User $user,
        ChartAccount $asset,
        ChartAccount $equity,
        float $amount,
        string $number = 'OPENING-BANK'
    ): void {
        $entry = JournalEntry::create([
            'kind' => 'payment',
            'number' => $number,
            'entry_date' => '2026-10-20',
            'memo' => 'Opening balance',
            'created_by' => $user->id,
        ]);
        $entry->lines()->createMany([
            ['chart_account_id' => $asset->id, 'debit' => $amount, 'credit' => 0],
            ['chart_account_id' => $equity->id, 'debit' => 0, 'credit' => $amount],
        ]);
    }
}
