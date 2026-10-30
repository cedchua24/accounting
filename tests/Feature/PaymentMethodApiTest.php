<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentMethodApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_methods_can_be_listed_and_filtered(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $bank = PaymentMethod::create([
            'code' => 'BANK', 'name' => 'Bank transfer', 'type' => 'bank_transfer',
        ]);
        $card = PaymentMethod::create([
            'code' => 'CARD', 'name' => 'Credit card', 'type' => 'credit_card',
            'creates_obligation' => true,
        ]);
        PaymentMethod::create([
            'code' => 'OLD-CASH', 'name' => 'Old cash method', 'type' => 'cash',
            'is_active' => false,
        ]);

        $this->getJson('/api/payment-methods')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $bank->id)
            ->assertJsonPath('data.1.id', $card->id);

        $this->getJson('/api/payment-methods?active=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['code' => 'OLD-CASH']);

        $this->getJson('/api/payment-methods?type=credit_card&creates_obligation=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $card->id)
            ->assertJsonPath('data.0.type', 'credit_card');
    }

    public function test_credit_card_payment_methods_only_include_applicable_choices(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $ledger = ChartAccount::create(['code' => 'CC-PAY-METHODS', 'name' => 'Card payable', 'type' => 'liability']);
        $card = FinancialAccount::create([
            'name' => 'Test Card', 'kind' => 'credit_card', 'currency' => 'PHP',
            'chart_account_id' => $ledger->id, 'is_company_wide' => true,
        ]);
        $cash = PaymentMethod::create(['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash']);
        $bank = PaymentMethod::create(['code' => 'ONLINE', 'name' => 'Online Transfer', 'type' => 'bank_transfer']);
        PaymentMethod::create(['code' => 'CHEQUE', 'name' => 'Cheque', 'type' => 'cheque']);
        $wallet = PaymentMethod::create(['code' => 'WALLET', 'name' => 'E-Wallet', 'type' => 'e_wallet']);
        PaymentMethod::create([
            'code' => 'CARD', 'name' => 'Credit Card', 'type' => 'credit_card',
            'creates_obligation' => true,
        ]);
        PaymentMethod::create(['code' => 'IBS', 'name' => 'Interbranch Settlement', 'type' => 'other']);
        PaymentMethod::create([
            'code' => 'OBLIGATION', 'name' => 'Obligation Transfer', 'type' => 'bank_transfer',
            'creates_obligation' => true,
        ]);
        PaymentMethod::create([
            'code' => 'INACTIVE', 'name' => 'Inactive Cash', 'type' => 'cash',
            'is_active' => false,
        ]);

        $this->getJson("/api/credit-cards/{$card->id}/payment-methods")
            ->assertOk()
            ->assertJsonPath('credit_card_id', $card->id)
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment([
                'id' => $cash->id,
                'compatible_funding_account_kinds' => ['cash'],
            ])
            ->assertJsonFragment([
                'id' => $bank->id,
                'compatible_funding_account_kinds' => ['bank_account', 'checking_account'],
            ])
            ->assertJsonFragment([
                'id' => $wallet->id,
                'compatible_funding_account_kinds' => ['e_wallet'],
            ])
            ->assertJsonMissing(['code' => 'CHEQUE'])
            ->assertJsonMissing(['code' => 'CARD'])
            ->assertJsonMissing(['code' => 'IBS'])
            ->assertJsonMissing(['code' => 'OBLIGATION'])
            ->assertJsonMissing(['code' => 'INACTIVE']);
    }

    public function test_all_active_compatible_financial_accounts_are_available_as_card_funding_sources(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cardLedger = ChartAccount::create(['code' => 'CC-FUND', 'name' => 'Card payable', 'type' => 'liability']);
        $walletOneLedger = ChartAccount::create(['code' => 'EW-1', 'name' => 'Wallet One', 'type' => 'asset']);
        $walletTwoLedger = ChartAccount::create(['code' => 'EW-2', 'name' => 'Wallet Two', 'type' => 'asset']);
        $historicalLedger = ChartAccount::create(['code' => 'EW-OLD', 'name' => 'Historical Wallet', 'type' => 'asset']);
        $card = FinancialAccount::create([
            'name' => 'Test Card', 'kind' => 'credit_card', 'currency' => 'PHP',
            'chart_account_id' => $cardLedger->id, 'is_company_wide' => true,
        ]);
        $walletOne = FinancialAccount::create([
            'name' => 'GCash One', 'kind' => 'e_wallet', 'currency' => 'PHP',
            'chart_account_id' => $walletOneLedger->id, 'is_company_wide' => true,
            'usable_for_payments' => true,
        ]);
        $walletOne->applications()->create(['application' => 'customer_receipt', 'source' => 'manual']);
        $walletTwo = FinancialAccount::create([
            'name' => 'GCash Two', 'kind' => 'e_wallet', 'currency' => 'PHP',
            'chart_account_id' => $walletTwoLedger->id, 'is_company_wide' => true,
            'usable_for_payments' => true,
        ]);
        $walletTwo->applications()->create(['application' => 'expense_payment', 'source' => 'manual']);
        $historical = FinancialAccount::create([
            'name' => 'Historical GCash', 'kind' => 'e_wallet', 'currency' => 'PHP',
            'chart_account_id' => $historicalLedger->id, 'is_company_wide' => true,
            'usable_for_payments' => false, 'is_active' => false,
        ]);
        $method = PaymentMethod::create([
            'code' => 'EWALLET-FUND', 'name' => 'E-Wallet', 'type' => 'e_wallet',
        ]);

        $this->getJson("/api/credit-cards/{$card->id}/funding-accounts?payment_method_id={$method->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $walletOne->id, 'name' => 'GCash One'])
            ->assertJsonFragment(['id' => $walletTwo->id, 'name' => 'GCash Two'])
            ->assertJsonMissing(['id' => $historical->id]);
    }
}
