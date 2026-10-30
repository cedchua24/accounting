<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransfer;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardRecentActivityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_frontend_ready_activity_from_payments_and_transfers(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$branch, $offset] = $this->accountingReferences();
        $bank = $this->financialAccount('BDO Savings', 'bank_account', '1020');
        $wallet = $this->financialAccount('GCash', 'e_wallet', '1030');
        $method = PaymentMethod::create([
            'code' => 'BANK', 'name' => 'Bank transfer', 'type' => 'bank_transfer',
        ]);

        $receipt = $this->payment(
            $branch, $offset, 'PAY-IN', 'customer', 'inflow', '2026-10-11', 24500, '2026-10-11 10:42:00'
        );
        $receipt->lines()->create([
            'financial_account_id' => $bank->id,
            'payment_method_id' => $method->id,
            'amount' => 24500,
        ]);

        $supplierPayment = $this->payment(
            $branch, $offset, 'PAY-OUT', 'purchase_order', 'outflow', '2026-10-11', 18650, '2026-10-11 09:18:00'
        );
        $supplierPayment->lines()->create([
            'financial_account_id' => $bank->id,
            'payment_method_id' => $method->id,
            'amount' => 18650,
        ]);

        FinancialAccountTransfer::create([
            'number' => 'TRF-00481',
            'source_financial_account_id' => $wallet->id,
            'destination_financial_account_id' => $bank->id,
            'transfer_date' => '2026-10-10',
            'currency' => 'PHP',
            'amount' => 35000,
            'status' => 'posted',
            'posted_at' => '2026-10-10 15:00:00',
        ]);
        FinancialAccountTransfer::create([
            'number' => 'OLD-TRANSFER',
            'source_financial_account_id' => $wallet->id,
            'destination_financial_account_id' => $bank->id,
            'transfer_date' => '2026-10-01',
            'currency' => 'PHP',
            'amount' => 99999,
            'status' => 'posted',
            'posted_at' => '2026-10-01 15:00:00',
        ]);

        $this->getJson('/api/dashboard/recent-activity?day_from=2026-10-10&day_to=2026-10-11&as_of=2026-10-11&limit=4')
            ->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('data.0.type', 'in')
            ->assertJsonPath('data.0.title', 'Customer payment')
            ->assertJsonPath('data.0.meta', 'Main · PAY-IN')
            ->assertJsonPath('data.0.account', 'BDO Savings')
            ->assertJsonPath('data.0.amount', '24500.0000')
            ->assertJsonPath('data.0.time', '10:42 AM')
            ->assertJsonPath('data.0.status', 'Posted')
            ->assertJsonPath('data.1.type', 'out')
            ->assertJsonPath('data.1.title', 'Supplier payment')
            ->assertJsonPath('data.1.amount', '-18650.0000')
            ->assertJsonPath('data.1.time', '9:18 AM')
            ->assertJsonPath('data.2.type', 'transfer')
            ->assertJsonPath('data.2.title', 'Account transfer')
            ->assertJsonPath('data.2.meta', 'TRF-00481')
            ->assertJsonPath('data.2.account', 'GCash → BDO Savings')
            ->assertJsonPath('data.2.amount', '35000.0000')
            ->assertJsonPath('data.2.time', 'Yesterday');
    }

    public function test_it_applies_the_global_display_limit(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$branch, $offset] = $this->accountingReferences();

        foreach ([8, 9, 10] as $hour) {
            $this->payment(
                $branch,
                $offset,
                "PAY-{$hour}",
                'expense',
                'outflow',
                '2026-10-11',
                100,
                "2026-10-11 {$hour}:00:00"
            );
        }

        $this->getJson('/api/dashboard/recent-activity?as_of=2026-10-11&limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.meta', 'Main · PAY-10')
            ->assertJsonPath('data.1.meta', 'Main · PAY-9');
    }

    public function test_it_validates_filters_and_requires_authentication(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/dashboard/recent-activity?day_from=2026-10-20&day_to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_to');

        auth()->forgetGuards();
        $this->getJson('/api/dashboard/recent-activity')->assertUnauthorized();
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

    private function financialAccount(string $name, string $kind, string $code): FinancialAccount
    {
        $ledger = ChartAccount::create(['code' => $code, 'name' => $name, 'type' => 'asset']);

        return FinancialAccount::create([
            'name' => $name,
            'kind' => $kind,
            'currency' => 'PHP',
            'chart_account_id' => $ledger->id,
            'is_company_wide' => true,
        ]);
    }

    private function payment(
        Branch $branch,
        ChartAccount $offset,
        string $number,
        string $type,
        string $direction,
        string $date,
        int $amount,
        string $postedAt
    ): Payment {
        return Payment::create([
            'branch_id' => $branch->id,
            'offset_account_id' => $offset->id,
            'number' => $number,
            'type' => $type,
            'direction' => $direction,
            'status' => 'posted',
            'payment_date' => $date,
            'currency' => 'PHP',
            'amount' => $amount,
            'posted_at' => $postedAt,
        ]);
    }
}
