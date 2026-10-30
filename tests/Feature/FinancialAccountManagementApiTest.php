<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\FinancialAccountApplicationType;
use App\Models\FinancialInstitution;
use App\Models\User;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialAccountManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_edit_one_account_detail_without_resending_initialization_data(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $branch = $this->createBranch();
        $account = $this->createBankAccount([
            'owner_branch_id' => $branch->id,
            'is_company_wide' => false,
        ]);
        $account->accessibleBranches()->attach($branch);

        $this->patchJson("/api/financial-accounts/{$account->id}", [
            'name' => 'Updated Payroll Account',
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Payroll Account')
            ->assertJsonPath('masked_account_number', '•••• 7890');

        $this->assertDatabaseHas('financial_accounts', [
            'id' => $account->id,
            'name' => 'Updated Payroll Account',
            'credit_limit' => null,
        ]);
        $this->assertDatabaseHas('financial_account_branch_access', [
            'financial_account_id' => $account->id,
            'branch_id' => $branch->id,
        ]);
    }

    public function test_branch_access_is_created_and_updated_with_a_financial_account(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $branchOne = $this->createBranch('B1', 'Branch One');
        $branchTwo = $this->createBranch('B2', 'Branch Two');
        $institution = FinancialInstitution::create(['code' => 'ACCESS-BANK', 'name' => 'Access Bank']);
        $ledger = ChartAccount::create(['code' => 'ACCESS-BANK-LEDGER', 'name' => 'Access Bank ledger', 'type' => 'asset']);

        $response = $this->postJson('/api/financial-accounts', [
            'owner_branch_id' => $branchOne->id,
            'is_company_wide' => false,
            'branch_ids' => [$branchOne->id, $branchTwo->id],
            'financial_institution_id' => $institution->id,
            'chart_account_id' => $ledger->id,
            'kind' => 'bank_account',
            'name' => 'Restricted Bank',
            'account_number' => '99887766',
            'currency' => 'PHP',
        ])->assertCreated()
            ->assertJsonPath('branch_ids', [$branchOne->id, $branchTwo->id]);

        $accountId = $response->json('id');
        $this->assertDatabaseHas('financial_account_branch_access', [
            'financial_account_id' => $accountId,
            'branch_id' => $branchOne->id,
        ]);
        $this->assertDatabaseHas('financial_account_branch_access', [
            'financial_account_id' => $accountId,
            'branch_id' => $branchTwo->id,
        ]);

        $this->patchJson("/api/financial-accounts/{$accountId}", [
            'owner_branch_id' => $branchTwo->id,
            'branch_ids' => [$branchTwo->id],
        ])->assertOk()
            ->assertJsonPath('branch_ids', [$branchTwo->id]);

        $this->assertDatabaseMissing('financial_account_branch_access', [
            'financial_account_id' => $accountId,
            'branch_id' => $branchOne->id,
        ]);
    }

    public function test_employee_can_edit_all_credit_card_initialization_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $institution = FinancialInstitution::create(['code' => 'NEWBANK', 'name' => 'New Bank']);
        $ledger = ChartAccount::create(['code' => '2020', 'name' => 'New card ledger', 'type' => 'liability']);
        $account = $this->createBankAccount();
        $holder = UserAccount::firstWhere('code', 'EXAMPLE-COMPANY');

        $response = $this->putJson("/api/financial-accounts/{$account->id}", [
            'is_company_wide' => true,
            'financial_institution_id' => $institution->id,
            'chart_account_id' => $ledger->id,
            'kind' => 'credit_card',
            'name' => 'Corporate Rewards Card',
            'account_number' => '5555 4444 3333 2222',
            'account_holder' => $holder->id,
            'description' => 'Primary employee expense card',
            'currency' => 'usd',
            'credit_limit' => 250000,
            'statement_day' => 15,
            'due_day' => 5,
            'buffer_days' => 3,
            'usable_for_receipts' => false,
            'usable_for_payments' => true,
            'is_active' => true,
            'application_ids' => [$this->applicationTypeId('expense_payment')],
        ])->assertOk()
            ->assertJsonPath('kind', 'credit_card')
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('credit_limit', '250000.0000')
            ->assertJsonPath('available_balance', '250000.0000')
            ->assertJsonPath('account_holder', $holder->id)
            ->assertJsonPath('user_account.name', 'Example Company')
            ->assertJsonPath('masked_account_number', '•••• 2222')
            ->assertJsonMissingPath('account_number');

        $this->assertSame('5555444433332222', $account->fresh()->account_number);
        $this->assertDatabaseHas('financial_account_applications', [
            'financial_account_id' => $account->id,
            'application_id' => $this->applicationTypeId('expense_payment'),
            'source' => 'manual',
        ]);
    }

    public function test_restricted_account_requires_branch_access_when_scope_is_changed(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->createBankAccount();

        $this->patchJson("/api/financial-accounts/{$account->id}", [
            'is_company_wide' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('branch_ids');
    }

    public function test_application_ids_can_be_updated(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->createBankAccount();
        $customerReceiptId = $this->applicationTypeId('customer_receipt');
        $expensePaymentId = $this->applicationTypeId('expense_payment');
        $accountTransferId = $this->applicationTypeId('account_transfer');

        $this->patchJson("/api/financial-accounts/{$account->id}", [
            'application_ids' => [$customerReceiptId, $expensePaymentId, $accountTransferId],
        ])->assertOk()
            ->assertJsonPath('applications.0.application_id', $customerReceiptId)
            ->assertJsonPath('applications.1.application_id', $expensePaymentId)
            ->assertJsonPath('applications.2.application_id', $accountTransferId)
            ->assertJsonMissingPath('applications.0.application');

        $this->assertDatabaseHas('financial_account_applications', [
            'financial_account_id' => $account->id,
            'application_id' => $this->applicationTypeId('customer_receipt'),
        ]);
    }

    public function test_credit_limit_edit_can_round_trip_an_existing_legacy_application(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->createBankAccount([
            'kind' => 'credit_card',
            'credit_limit' => 300000,
            'statement_day' => 3,
            'due_day' => 26,
        ]);
        $legacyApplication = $account->applications()->create([
            'application' => FinancialAccountApplication::UNCLASSIFIED,
            'source' => 'legacy_import',
        ]);

        $this->patchJson("/api/financial-accounts/{$account->id}", [
            'credit_limit' => 400000,
            'application_ids' => [$legacyApplication->application_id],
        ])->assertOk()
            ->assertJsonPath('credit_limit', '400000.0000');

        $this->assertDatabaseHas('financial_account_applications', [
            'id' => $legacyApplication->id,
            'financial_account_id' => $account->id,
            'application_id' => $this->applicationTypeId(FinancialAccountApplication::UNCLASSIFIED),
            'source' => 'legacy_import',
        ]);
    }

    public function test_employee_can_list_active_financial_account_application_types(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/financial-account-application-types')
            ->assertOk()
            ->assertJsonCount(9)
            ->assertJsonPath('0.code', FinancialAccountApplication::CUSTOMER_RECEIPT)
            ->assertJsonMissing(['code' => FinancialAccountApplication::UNCLASSIFIED]);

        $this->getJson('/api/financial-account-application-types?active=0')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.code', FinancialAccountApplication::UNCLASSIFIED);
    }

    private function createBankAccount(array $overrides = []): FinancialAccount
    {
        $holder = UserAccount::firstOrCreate(
            ['code' => 'EXAMPLE-COMPANY'],
            ['type' => 'company', 'name' => 'Example Company', 'is_active' => true]
        );
        $institution = FinancialInstitution::create([
            'code' => 'BANK'.FinancialInstitution::count(),
            'name' => 'Bank '.FinancialInstitution::count(),
        ]);
        $ledger = ChartAccount::create([
            'code' => '1010-'.ChartAccount::count(),
            'name' => 'Bank ledger '.ChartAccount::count(),
            'type' => 'asset',
        ]);

        return FinancialAccount::create(array_merge([
            'is_company_wide' => true,
            'financial_institution_id' => $institution->id,
            'chart_account_id' => $ledger->id,
            'kind' => 'bank_account',
            'name' => 'Payroll Account',
            'account_number' => '1234567890',
            'account_holder' => $holder->id,
            'currency' => 'PHP',
            'usable_for_receipts' => true,
            'usable_for_payments' => true,
        ], $overrides));
    }

    private function applicationTypeId(string $code): int
    {
        return (int) FinancialAccountApplicationType::where('code', $code)->value('id');
    }

    private function createBranch(string $code = 'MAIN', string $name = 'Main'): Branch
    {
        $inventory = ChartAccount::create(['code' => $code.'-INV', 'name' => $name.' inventory', 'type' => 'asset']);
        $dueFrom = ChartAccount::create(['code' => $code.'-DF', 'name' => $name.' due from', 'type' => 'asset']);
        $dueTo = ChartAccount::create(['code' => $code.'-DT', 'name' => $name.' due to', 'type' => 'liability']);

        return Branch::create([
            'code' => $code,
            'name' => $name,
            'inventory_account_id' => $inventory->id,
            'due_from_account_id' => $dueFrom->id,
            'due_to_account_id' => $dueTo->id,
            'is_active' => true,
        ]);
    }
}
