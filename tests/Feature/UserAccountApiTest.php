<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialInstitution;
use App\Models\User;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_account_can_be_created_and_used_as_financial_account_holder(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $holder = $this->postJson('/api/user-accounts', [
            'user_id' => $user->id,
            'code' => 'holder-001',
            'type' => 'individual',
            'name' => 'Ced Chua',
            'email' => 'ced@example.test',
            'phone' => '+63 900 000 0000',
            'tax_identifier' => 'TIN-123',
            'details' => ['preferred_name' => 'Ced', 'verified' => true],
        ])->assertCreated()
            ->assertJsonPath('code', 'HOLDER-001')
            ->assertJsonPath('details.preferred_name', 'Ced')
            ->assertJsonPath('financial_accounts_count', 0)
            ->assertJsonPath('loans_count', 0)
            ->json();

        $ledger = ChartAccount::create(['code' => '1025', 'name' => 'Holder Bank', 'type' => 'asset']);
        $account = $this->postJson('/api/financial-accounts', [
            'account_holder' => $holder['id'],
            'is_company_wide' => true,
            'chart_account_id' => $ledger->id,
            'kind' => 'cash',
            'name' => 'Ced Cash',
            'currency' => 'PHP',
        ])->assertCreated()
            ->assertJsonPath('user_account.id', $holder['id'])
            ->assertJsonPath('user_account.name', 'Ced Chua')
            ->assertJsonPath('account_holder', $holder['id'])
            ->json();

        $this->getJson('/api/user-accounts?active=1&search=Ced')
            ->assertOk()
            ->assertJsonPath('data.0.id', $holder['id'])
            ->assertJsonPath('data.0.financial_accounts_count', 1)
            ->assertJsonPath('data.0.loans_count', 0);

        $this->patchJson("/api/user-accounts/{$holder['id']}", [
            'name' => 'Ced Chua Updated',
            'details' => ['preferred_name' => 'Cedrick'],
        ])->assertOk()
            ->assertJsonPath('code', 'HOLDER-001')
            ->assertJsonPath('name', 'Ced Chua Updated')
            ->assertJsonPath('details.preferred_name', 'Cedrick')
            ->assertJsonPath('financial_accounts_count', 1);

        $this->getJson("/api/user-accounts/{$holder['id']}")
            ->assertOk()
            ->assertJsonPath('name', 'Ced Chua Updated')
            ->assertJsonCount(1, 'financial_accounts')
            ->assertJsonCount(0, 'loans');

        $this->deleteJson("/api/user-accounts/{$holder['id']}")->assertStatus(409);
        $this->getJson("/api/financial-accounts/{$account['id']}")
            ->assertOk()->assertJsonPath('user_account.id', $holder['id']);
    }

    public function test_inactive_holder_cannot_be_assigned_to_a_financial_account(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $holder = UserAccount::create([
            'code' => 'INACTIVE', 'type' => 'company', 'name' => 'Inactive Holder', 'is_active' => false,
        ]);
        $ledger = ChartAccount::create(['code' => '1026', 'name' => 'Inactive Holder Cash', 'type' => 'asset']);

        $this->postJson('/api/financial-accounts', [
            'account_holder' => $holder->id,
            'is_company_wide' => true,
            'chart_account_id' => $ledger->id,
            'kind' => 'cash',
            'name' => 'Invalid Cash',
            'currency' => 'PHP',
        ])->assertUnprocessable()->assertJsonValidationErrors('account_holder');
    }

    public function test_get_all_returns_a_non_paginated_filtered_user_account_list(): void
    {
        Sanctum::actingAs(User::factory()->create());
        UserAccount::create([
            'code' => 'KATHLEEN-MAE', 'type' => 'individual', 'name' => 'KATHLEEN MAE PANGILINAN', 'is_active' => true,
        ]);
        UserAccount::create([
            'code' => 'MDR-CONSUMER', 'type' => 'company', 'name' => 'MDR Consumer Goods Trading', 'is_active' => true,
        ]);
        UserAccount::create([
            'code' => 'INACTIVE-HOLDER', 'type' => 'individual', 'name' => 'Inactive Holder', 'is_active' => false,
        ]);

        $this->getJson('/api/user-accounts/list?active=1')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'KATHLEEN MAE PANGILINAN')
            ->assertJsonPath('1.name', 'MDR Consumer Goods Trading')
            ->assertJsonMissingPath('data');

        $this->getJson('/api/user-accounts/list?type=company&search=MDR')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.code', 'MDR-CONSUMER');
    }

    public function test_unlinked_user_account_can_be_updated_partially_and_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $holder = $this->postJson('/api/user-accounts', [
            'code' => 'delete-me',
            'type' => 'individual',
            'name' => 'Delete Me',
        ])->assertCreated()->json();

        $this->patchJson("/api/user-accounts/{$holder['id']}", [
            'is_active' => false,
            'phone' => '+63 900 111 2222',
        ])->assertOk()
            ->assertJsonPath('code', 'DELETE-ME')
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('phone', '+63 900 111 2222');

        $this->deleteJson("/api/user-accounts/{$holder['id']}")->assertNoContent();
        $this->assertDatabaseMissing('user_accounts', ['id' => $holder['id']]);
    }

    public function test_user_account_linked_to_a_loan_cannot_be_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $holder = UserAccount::create([
            'code' => 'LOAN-HOLDER',
            'type' => 'individual',
            'name' => 'Loan Holder',
            'is_active' => true,
        ]);
        $institution = FinancialInstitution::create([
            'code' => 'LOAN-BANK',
            'name' => 'Loan Bank',
            'type' => 'bank',
            'is_active' => true,
        ]);
        $loan = $this->postJson('/api/loans', [
            'number' => 'LOAN-USER-ACCOUNT-CRUD',
            'direction' => 'borrowed',
            'borrower_type' => 'bank',
            'financial_institution_id' => $institution->id,
            'user_account_id' => $holder->id,
            'start_date' => '2026-10-30',
            'first_due_date' => '2026-11-30',
            'currency' => 'PHP',
            'principal_amount' => 100000,
            'total_interest_amount' => 10000,
            'term_months' => 1,
        ])->assertCreated()->json();

        $this->getJson("/api/user-accounts/{$holder->id}")
            ->assertOk()
            ->assertJsonPath('loans_count', 1)
            ->assertJsonCount(1, 'loans')
            ->assertJsonPath('loans.0.id', $loan['id']);

        $this->deleteJson("/api/user-accounts/{$holder->id}")
            ->assertStatus(409);
        $this->assertDatabaseHas('user_accounts', ['id' => $holder->id]);
    }
}
