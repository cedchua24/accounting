<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\PaymentMethod;
use Database\Seeders\AccountingSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportLegacyPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.legacy_mysql', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('legacy_mysql');
        $this->createLegacySchema();
        $this->seedLegacyRows();
        $this->seed(AccountingSeeder::class);
    }

    protected function tearDown(): void
    {
        DB::purge('legacy_mysql');
        parent::tearDown();
    }

    public function test_it_dry_runs_then_imports_branch_scoped_customer_and_po_payments_idempotently(): void
    {
        $ledger = ChartAccount::create(['code' => 'LEGACY-CASH', 'name' => 'Legacy Cash', 'type' => 'asset']);
        $account = FinancialAccount::create([
            'chart_account_id' => $ledger->id,
            'kind' => 'cash',
            'name' => 'Legacy Store Cash',
            'currency' => 'PHP',
            'is_company_wide' => true,
            'usable_for_receipts' => true,
            'usable_for_payments' => true,
            'is_active' => true,
            'legacy_source' => 'baker.payment_type_po',
            'legacy_id' => 1,
        ]);
        foreach ([
            FinancialAccountApplication::CUSTOMER_RECEIPT,
            FinancialAccountApplication::PURCHASE_ORDER_PAYMENT,
        ] as $application) {
            $account->applications()->create(['application' => $application, 'source' => 'test']);
        }

        $dryRunExit = Artisan::call('accounting:import-legacy-payments', [
            '--source' => 'bakingstore-branch-1',
            '--account-source' => 'baker',
            '--dry-run' => true,
        ]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $dryRunExit, Artisan::output());

        $importExit = Artisan::call('accounting:import-legacy-payments', [
            '--source' => 'bakingstore-branch-1',
            '--account-source' => 'baker',
        ]);
        $this->assertSame(0, $importExit, Artisan::output());

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('payment_lines', 2);
        $this->assertDatabaseHas('payment_lines', [
            'legacy_source' => 'bakingstore-branch-1.mode_of_payment',
            'legacy_id' => 101,
            'amount' => 60,
        ]);
        $this->assertDatabaseHas('payment_lines', [
            'legacy_source' => 'bakingstore-branch-1.mode_of_payment_po',
            'legacy_id' => 201,
            'amount' => 80,
        ]);
        $this->assertDatabaseHas('legacy_entity_mappings', [
            'source_system' => 'bakingstore-branch-1',
            'legacy_table' => 'payment_type_po',
            'legacy_id' => 1,
            'central_id' => $account->id,
        ]);
        $this->assertDatabaseMissing('payment_lines', [
            'legacy_source' => 'bakingstore-branch-2.mode_of_payment',
            'legacy_id' => 101,
        ]);
        $this->assertNotNull(DB::table('payment_lines')->where('legacy_id', 101)->value('confirmed_at'));

        $repeatExit = Artisan::call('accounting:import-legacy-payments', [
            '--source' => 'bakingstore-branch-1',
            '--account-source' => 'baker',
        ]);
        $this->assertSame(0, $repeatExit, Artisan::output());
        $this->assertDatabaseCount('payments', 2);
    }

    private function createLegacySchema(): void
    {
        $schema = Schema::connection('legacy_mysql');
        $schema->create('shop', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('shop_name');
        });
        $schema->create('customer', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('store_name')->nullable();
        });
        $schema->create('shop_order_transaction', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('requestor');
            $table->decimal('shop_order_transaction_total_price', 19, 4);
            $table->date('date')->nullable();
            $table->date('created_at')->nullable();
        });
        $schema->create('mode_of_payment', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('payment_type_id');
            $table->unsignedBigInteger('shop_order_transaction_id');
            $table->decimal('amount', 19, 4);
            $table->integer('is_paid')->default(0);
            $table->date('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        $schema->create('supplier', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('supplier_name');
        });
        $schema->create('order_supplier_transaction', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('supplier_id');
            $table->string('invoice_number')->nullable();
            $table->decimal('total_transaction_price', 19, 4);
            $table->date('order_date')->nullable();
            $table->date('created_at')->nullable();
        });
        $schema->create('mode_of_payment_po', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('payment_type_po_id');
            $table->unsignedBigInteger('payment_term_id');
            $table->unsignedBigInteger('order_supplier_transaction_id');
            $table->decimal('amount', 19, 4);
            $table->date('date')->nullable();
        });
        $schema->create('installment_payment_transaction', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('mode_of_payment_po_id');
            $table->unsignedInteger('number_of_months')->default(1);
        });
    }

    private function seedLegacyRows(): void
    {
        $legacy = DB::connection('legacy_mysql');
        $legacy->table('shop')->insert(['id' => 5, 'shop_name' => 'MDR Commonwealth']);
        $legacy->table('customer')->insert(['id' => 15, 'first_name' => 'Juan', 'last_name' => 'Customer']);
        $legacy->table('shop_order_transaction')->insert([
            'id' => 900, 'shop_id' => 5, 'requestor' => 15,
            'shop_order_transaction_total_price' => 100,
            'date' => '2026-10-04', 'created_at' => '2026-10-04',
        ]);
        $legacy->table('mode_of_payment')->insert([
            'id' => 101, 'payment_type_id' => 1, 'shop_order_transaction_id' => 900,
            'amount' => 60, 'is_paid' => 1, 'created_at' => '2026-10-04',
            'updated_at' => '2026-10-04 12:00:00',
        ]);
        $legacy->table('supplier')->insert(['id' => 30, 'supplier_name' => 'Test Supplier']);
        $legacy->table('order_supplier_transaction')->insert([
            'id' => 800, 'supplier_id' => 30, 'invoice_number' => 'INV-800',
            'total_transaction_price' => 200, 'order_date' => '2026-10-03', 'created_at' => '2026-10-03',
        ]);
        $legacy->table('mode_of_payment_po')->insert([
            'id' => 201, 'payment_type_po_id' => 1, 'payment_term_id' => 1,
            'order_supplier_transaction_id' => 800, 'amount' => 80, 'date' => '2026-10-05',
        ]);
    }
}
