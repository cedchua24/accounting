<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Counterparty;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialAccountTransactionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_transactions_include_payments_and_their_source_documents(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $bankLedger = ChartAccount::create(['code' => '1010', 'name' => 'BDO Savings', 'type' => 'asset']);
        $offset = ChartAccount::create(['code' => '5000', 'name' => 'Payment offset', 'type' => 'expense']);
        $branch = $this->branch();
        $account = FinancialAccount::create([
            'name' => 'BDO Savings', 'kind' => 'bank_account', 'currency' => 'PHP',
            'chart_account_id' => $bankLedger->id, 'is_company_wide' => true,
        ]);
        $method = PaymentMethod::create([
            'code' => 'BANK', 'name' => 'Bank transfer', 'type' => 'bank_transfer',
        ]);
        $customer = Counterparty::create(['type' => 'customer', 'code' => 'CUS-1', 'name' => 'Customer One']);
        $supplier = Counterparty::create(['type' => 'supplier', 'code' => 'SUP-1', 'name' => 'Supplier One']);
        $invoice = SalesInvoice::create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'number' => 'CO-1001',
            'invoice_date' => '2026-10-10', 'currency' => 'PHP', 'total_amount' => 1500,
        ]);
        $purchaseOrder = PurchaseOrder::create([
            'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'number' => 'PO-1001',
            'order_date' => '2026-10-11', 'currency' => 'PHP', 'total_amount' => 500,
        ]);
        $expense = Expense::create([
            'branch_id' => $branch->id, 'payee_id' => $supplier->id, 'chart_account_id' => $offset->id,
            'number' => 'EXP-1001', 'expense_date' => '2026-10-12', 'currency' => 'PHP',
            'amount' => 250, 'description' => 'Delivery expense',
        ]);

        $customerPayment = $this->payment($account, $method, $branch, $offset, $customer, 'customer', $invoice, 1500, 'PAY-CO-1', true);
        $purchaseOrderPayment = $this->payment($account, $method, $branch, $offset, $supplier, 'purchase_order', $purchaseOrder, 500, 'PAY-PO-1', false);
        $expensePayment = $this->payment($account, $method, $branch, $offset, $supplier, 'expense', $expense, 250, 'PAY-EXP-1', false);
        $purchaseOrderPayment->update(['status' => 'voided']);
        $customerPayment->lines()->firstOrFail()->update([
            'confirmed_at' => '2026-10-12 10:30:00',
            'confirmed_by' => $user->id,
        ]);
        $this->putJson('/api/integrations/customer-payment-lines/'.$expensePayment->lines()->firstOrFail()->id.'/confirmation', [
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('confirmed_by.id', $user->id);

        $response = $this->getJson("/api/financial-accounts/{$account->id}/transactions")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('summary.total_debit', '1500.0000')
            ->assertJsonPath('summary.total_credit', '750.0000');
        $transactions = collect($response->json('data'))->keyBy('transaction.source_type');

        $this->assertSame('CO-1001', $transactions['customer_order']['transaction']['source_number']);
        $this->assertSame('PAY-CO-1', $transactions['customer_order']['transaction']['payment']['number']);
        $this->assertSame('Customer One', $transactions['customer_order']['transaction']['counterparty']['name']);
        $this->assertTrue($transactions['customer_order']['transaction']['payment_line']['confirmed']);
        $this->assertSame($user->name, $transactions['customer_order']['transaction']['payment_line']['confirmed_by']['name']);
        $this->assertSame('DEBIT', $transactions['customer_order']['event']);
        $this->assertSame('PO-1001', $transactions['purchase_order']['transaction']['source_number']);
        $this->assertSame('Supplier One', $transactions['purchase_order']['transaction']['counterparty']['name']);
        $this->assertSame('CREDIT', $transactions['purchase_order']['event']);
        $this->assertSame('EXP-1001', $transactions['expense']['transaction']['source_number']);
        $this->assertSame('Bank transfer', $transactions['expense']['transaction']['payment_method']['name']);
        $this->assertTrue($transactions['expense']['transaction']['payment_line']['confirmed']);
        $this->assertSame('CREDIT', $transactions['expense']['event']);

        $this->getJson("/api/financial-accounts/{$account->id}/transactions?date_from=2026-10-11&date_to=2026-10-11")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.transaction.source_type', 'purchase_order');
        $this->getJson("/api/financial-accounts/{$account->id}/transactions?event=DEBIT")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.transaction.source_type', 'customer_order')
            ->assertJsonPath('summary.total_debit', '1500.0000')->assertJsonPath('summary.total_credit', '0.0000');
        $this->getJson("/api/financial-accounts/{$account->id}/transactions?credit=500")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.transaction.source_type', 'purchase_order');
        $this->getJson("/api/financial-accounts/{$account->id}/transactions?status=voided")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.transaction.transaction_status', 'voided');
        $this->getJson("/api/financial-accounts/{$account->id}/transactions?branch_id={$branch->id}&sort=oldest")
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.entry_date', '2026-10-10');
    }

    private function payment(
        FinancialAccount $account,
        PaymentMethod $method,
        Branch $branch,
        ChartAccount $offset,
        Counterparty $counterparty,
        string $type,
        Model $document,
        float $amount,
        string $number,
        bool $inflow,
    ): Payment {
        $foreignKey = match ($type) {
            'customer' => 'sales_invoice_id',
            'purchase_order' => 'purchase_order_id',
            'expense' => 'expense_id',
        };
        $dateField = match ($type) {
            'customer' => 'invoice_date',
            'purchase_order' => 'order_date',
            'expense' => 'expense_date',
        };
        $transactionDate = $document->getAttribute($dateField)->toDateString();
        $payment = Payment::create([
            'branch_id' => $branch->id, 'number' => $number, 'type' => $type,
            'direction' => $inflow ? 'inflow' : 'outflow', 'counterparty_id' => $counterparty->id,
            $foreignKey => $document->id, 'offset_account_id' => $offset->id,
            'payment_date' => $transactionDate, 'currency' => 'PHP', 'amount' => $amount, 'status' => 'posted',
        ]);
        $payment->lines()->create([
            'financial_account_id' => $account->id, 'payment_method_id' => $method->id,
            'amount' => $amount, 'reference' => 'REF-'.$number,
        ]);
        $entry = $payment->journalEntries()->create([
            'kind' => 'payment', 'number' => 'JE-'.$number, 'entry_date' => $transactionDate,
        ]);
        $entry->lines()->create([
            'branch_id' => $branch->id, 'chart_account_id' => $account->chart_account_id,
            'counterparty_id' => $counterparty->id,
            'debit' => $inflow ? $amount : 0, 'credit' => $inflow ? 0 : $amount,
            'description' => $method->name,
        ]);

        return $payment;
    }

    private function branch(): Branch
    {
        $inventory = ChartAccount::create(['code' => 'B1-INV', 'name' => 'Inventory', 'type' => 'asset']);
        $dueFrom = ChartAccount::create(['code' => 'B1-DF', 'name' => 'Due From', 'type' => 'asset']);
        $dueTo = ChartAccount::create(['code' => 'B1-DT', 'name' => 'Due To', 'type' => 'liability']);

        return Branch::create([
            'code' => 'B1', 'name' => 'Main Branch', 'inventory_account_id' => $inventory->id,
            'due_from_account_id' => $dueFrom->id, 'due_to_account_id' => $dueTo->id,
        ]);
    }
}
