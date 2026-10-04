<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Counterparty;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Models\PaymentObligation;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditCardCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_charge_settles_the_po_and_creates_a_statement_that_can_be_paid_from_a_bank(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $branch = $this->branch();
        $payables = ChartAccount::create(['code' => '2001', 'name' => 'Accounts Payable', 'type' => 'liability']);
        $cardLiability = ChartAccount::create(['code' => '2101', 'name' => 'Corporate Card Payable', 'type' => 'liability']);
        $bankLedger = ChartAccount::create(['code' => '1001', 'name' => 'Operating Bank', 'type' => 'asset']);
        $supplier = Counterparty::create(['type' => 'supplier', 'code' => 'SUP-1', 'name' => 'Test Supplier']);
        $purchaseOrder = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PO-1',
            'order_date' => '2026-10-01',
            'currency' => 'PHP',
            'total_amount' => 10000,
            'status' => 'open',
        ]);
        $card = FinancialAccount::create([
            'owner_branch_id' => $branch->id,
            'is_company_wide' => false,
            'chart_account_id' => $cardLiability->id,
            'kind' => 'credit_card',
            'name' => 'Corporate Visa',
            'account_number' => '4111111111111111',
            'currency' => 'PHP',
            'credit_limit' => 100000,
            'statement_day' => 17,
            'due_day' => 7,
            'usable_for_payments' => true,
        ]);
        $card->accessibleBranches()->attach($branch->id);
        $bank = FinancialAccount::create([
            'owner_branch_id' => $branch->id,
            'is_company_wide' => false,
            'chart_account_id' => $bankLedger->id,
            'kind' => 'bank_account',
            'name' => 'Operating Bank',
            'account_number' => 'BANK-001',
            'currency' => 'PHP',
            'usable_for_payments' => true,
        ]);
        $bank->accessibleBranches()->attach($branch->id);
        $cardMethod = PaymentMethod::create([
            'code' => 'CARD', 'name' => 'Corporate Credit Card', 'type' => 'credit_card', 'creates_obligation' => true,
        ]);
        $bankMethod = PaymentMethod::create([
            'code' => 'BANK', 'name' => 'Bank Transfer', 'type' => 'bank_transfer',
        ]);

        $charge = $this->postJson('/api/payments', [
            'branch_id' => $branch->id,
            'type' => 'purchase_order',
            'purchase_order_id' => $purchaseOrder->id,
            'offset_account_id' => $payables->id,
            'payment_date' => '2026-10-04',
            'currency' => 'PHP',
            'lines' => [[
                'financial_account_id' => $card->id,
                'payment_method_id' => $cardMethod->id,
                'amount' => 10000,
                'reference' => 'CARD-AUTH-1',
            ]],
            'post' => true,
        ])->assertCreated()->assertJsonPath('status', 'posted');

        $this->assertDatabaseHas('purchase_orders', ['id' => $purchaseOrder->id, 'status' => 'paid']);
        $this->assertDatabaseHas('payment_obligations', [
            'type' => 'credit_card_statement',
            'financial_account_id' => $card->id,
            'branch_id' => $branch->id,
            'principal_amount' => 10000,
            'status' => 'open',
        ]);
        $statement = PaymentObligation::where('financial_account_id', $card->id)->firstOrFail();
        $this->assertSame('2026-10-17', $statement->statement_date->toDateString());
        $this->assertSame('2026-11-07', $statement->due_date->toDateString());
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $payables->id, 'debit' => 10000, 'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $cardLiability->id, 'debit' => 0, 'credit' => 10000,
        ]);

        $obligationId = $charge->json('lines.0.obligation_charges.0.payment_obligation_id');
        $this->assertNotNull($obligationId);

        $this->postJson('/api/payments', [
            'branch_id' => $branch->id,
            'type' => 'chart_account',
            'offset_account_id' => $cardLiability->id,
            'payment_date' => '2026-11-07',
            'currency' => 'PHP',
            'lines' => [[
                'financial_account_id' => $bank->id,
                'payment_method_id' => $bankMethod->id,
                'amount' => 10000,
                'reference' => 'BANK-SETTLEMENT-1',
            ]],
            'allocations' => [[
                'payment_obligation_id' => $obligationId,
                'amount' => 10000,
            ]],
            'post' => true,
        ])->assertCreated()->assertJsonPath('status', 'posted');

        $this->assertDatabaseHas('payment_obligations', ['id' => $obligationId, 'status' => 'paid']);
        $this->assertDatabaseHas('purchase_orders', ['id' => $purchaseOrder->id, 'status' => 'paid']);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $cardLiability->id, 'debit' => 10000, 'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $bankLedger->id, 'debit' => 0, 'credit' => 10000,
        ]);
    }

    public function test_expense_card_installments_create_monthly_due_entries_and_can_be_settled_separately(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $branch = $this->branch();
        $expenseLedger = ChartAccount::create(['code' => '6101', 'name' => 'Office Supplies', 'type' => 'expense']);
        $cardLiability = ChartAccount::create(['code' => '2101', 'name' => 'Corporate Card Payable', 'type' => 'liability']);
        $bankLedger = ChartAccount::create(['code' => '1001', 'name' => 'Operating Bank', 'type' => 'asset']);
        $payee = Counterparty::create(['type' => 'supplier', 'code' => 'PAYEE-1', 'name' => 'Office Store']);
        $expense = Expense::create([
            'branch_id' => $branch->id,
            'number' => 'EXP-1',
            'payee_id' => $payee->id,
            'chart_account_id' => $expenseLedger->id,
            'expense_date' => '2026-10-20',
            'currency' => 'PHP',
            'amount' => 2500,
            'description' => 'Office supplies',
        ]);
        $card = FinancialAccount::create([
            'owner_branch_id' => $branch->id,
            'is_company_wide' => false,
            'chart_account_id' => $cardLiability->id,
            'kind' => 'credit_card',
            'name' => 'Corporate Visa',
            'account_number' => '4111111111111111',
            'currency' => 'PHP',
            'credit_limit' => 100000,
            'statement_day' => 17,
            'due_day' => 7,
            'usable_for_payments' => true,
        ]);
        $card->accessibleBranches()->attach($branch->id);
        $bank = FinancialAccount::create([
            'owner_branch_id' => $branch->id,
            'is_company_wide' => false,
            'chart_account_id' => $bankLedger->id,
            'kind' => 'bank_account',
            'name' => 'Operating Bank',
            'account_number' => 'BANK-001',
            'currency' => 'PHP',
            'usable_for_payments' => true,
        ]);
        $bank->accessibleBranches()->attach($branch->id);
        $cardMethod = PaymentMethod::create([
            'code' => 'CARD', 'name' => 'Corporate Credit Card', 'type' => 'credit_card', 'creates_obligation' => true,
        ]);
        $bankMethod = PaymentMethod::create([
            'code' => 'BANK', 'name' => 'Bank Transfer', 'type' => 'bank_transfer',
        ]);

        $charge = $this->postJson('/api/payments', [
            'branch_id' => $branch->id,
            'type' => 'expense',
            'expense_id' => $expense->id,
            'offset_account_id' => $expenseLedger->id,
            'payment_date' => '2026-10-20',
            'currency' => 'PHP',
            'lines' => [[
                'financial_account_id' => $card->id,
                'payment_method_id' => $cardMethod->id,
                'amount' => 2500,
                'installment_count' => 3,
            ]],
            'post' => true,
        ])->assertCreated()->assertJsonPath('status', 'posted');

        $statements = PaymentObligation::where('financial_account_id', $card->id)->orderBy('statement_date')->get();
        $this->assertCount(3, $statements);
        $this->assertSame(
            ['2026-11-17', '2026-12-17', '2027-01-17'],
            $statements->map(fn ($statement) => $statement->statement_date->toDateString())->all()
        );
        $this->assertSame(
            ['2026-12-07', '2027-01-07', '2027-02-07'],
            $statements->map(fn ($statement) => $statement->due_date->toDateString())->all()
        );
        $this->assertSame(['833.3333', '833.3333', '833.3334'], $statements->pluck('principal_amount')->all());
        $this->assertSame([1, 2, 3], $charge->json('lines.0.obligation_charges.*.installment_number'));
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'status' => 'paid']);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $expenseLedger->id, 'debit' => 2500, 'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $cardLiability->id, 'debit' => 0, 'credit' => 2500,
        ]);

        $this->postJson('/api/payments', [
            'branch_id' => $branch->id,
            'type' => 'chart_account',
            'offset_account_id' => $cardLiability->id,
            'payment_date' => '2026-12-07',
            'currency' => 'PHP',
            'lines' => [[
                'financial_account_id' => $bank->id,
                'payment_method_id' => $bankMethod->id,
                'amount' => 833.3333,
            ]],
            'allocations' => [[
                'payment_obligation_id' => $statements[0]->id,
                'amount' => 833.3333,
            ]],
            'post' => true,
        ])->assertCreated()->assertJsonPath('status', 'posted');

        $this->assertDatabaseHas('payment_obligations', ['id' => $statements[0]->id, 'status' => 'paid']);
        $this->assertDatabaseHas('payment_obligations', ['id' => $statements[1]->id, 'status' => 'open']);
        $this->assertDatabaseHas('payment_obligations', ['id' => $statements[2]->id, 'status' => 'open']);
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'status' => 'paid']);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $cardLiability->id, 'debit' => 833.3333, 'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'chart_account_id' => $bankLedger->id, 'debit' => 0, 'credit' => 833.3333,
        ]);
    }

    private function branch(): Branch
    {
        $inventory = ChartAccount::create(['code' => 'B1-INV', 'name' => 'B1 Inventory', 'type' => 'asset']);
        $dueFrom = ChartAccount::create(['code' => 'B1-DF', 'name' => 'B1 Due From', 'type' => 'asset']);
        $dueTo = ChartAccount::create(['code' => 'B1-DT', 'name' => 'B1 Due To', 'type' => 'liability']);

        return Branch::create([
            'code' => 'B1', 'name' => 'Branch 1', 'inventory_account_id' => $inventory->id,
            'due_from_account_id' => $dueFrom->id, 'due_to_account_id' => $dueTo->id,
        ]);
    }
}
