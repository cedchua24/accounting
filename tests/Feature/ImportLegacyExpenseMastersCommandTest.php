<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportLegacyExpenseMastersCommandTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::purge('legacy_mysql');
        parent::tearDown();
    }

    public function test_it_dry_runs_and_then_imports_only_expense_masters_idempotently(): void
    {
        $this->assertSame(0, Artisan::call('accounting:import-legacy-expense-masters', ['--dry-run' => true]));
        $this->assertDatabaseMissing('expense_types', ['legacy_source' => 'baker.expenses_type_v2']);
        $this->assertDatabaseCount('expenses', 0);

        $this->assertSame(0, Artisan::call('accounting:import-legacy-expense-masters'));
        $this->assertDatabaseHas('chart_account_groups', [
            'code' => 'EXPENSE',
            'legacy_source' => 'baker.chart_of_accounts',
            'legacy_id' => 6,
        ]);
        $this->assertDatabaseHas('expense_types', [
            'legacy_source' => 'baker.expenses_type_v2',
            'legacy_id' => 1,
            'name' => 'Operating Expense',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('expense_categories', [
            'legacy_source' => 'baker.expenses_category_v2',
            'legacy_id' => 10,
            'name' => 'Bank & Financial Charges',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('expense_items', [
            'legacy_source' => 'baker.expenses_v2',
            'legacy_id' => 100,
            'code' => '01',
            'name' => 'Bank Service Fee',
            'is_hidden' => false,
        ]);
        $this->assertDatabaseHas('expense_items', [
            'legacy_source' => 'baker.expenses_v2',
            'legacy_id' => 101,
            'code' => '01',
            'name' => 'Hidden Legacy Fee',
            'is_hidden' => true,
        ]);
        $categoryAccountId = DB::table('expense_categories')
            ->where('legacy_source', 'baker.expenses_category_v2')
            ->where('legacy_id', 10)
            ->value('chart_account_id');
        $this->assertDatabaseHas('chart_accounts', [
            'id' => $categoryAccountId,
            'type' => 'expense',
            'is_postable' => true,
        ]);
        $this->assertSame(2, DB::table('expense_items')->where('expense_category_id', function ($query) {
            $query->select('id')->from('expense_categories')
                ->where('legacy_source', 'baker.expenses_category_v2')->where('legacy_id', 10);
        })->where('code', '01')->count());
        $this->assertDatabaseCount('expenses', 0);

        $counts = [
            DB::table('expense_types')->whereNotNull('legacy_id')->count(),
            DB::table('expense_categories')->whereNotNull('legacy_id')->count(),
            DB::table('expense_items')->whereNotNull('legacy_id')->count(),
        ];
        $this->assertSame(0, Artisan::call('accounting:import-legacy-expense-masters'));
        $this->assertSame($counts, [
            DB::table('expense_types')->whereNotNull('legacy_id')->count(),
            DB::table('expense_categories')->whereNotNull('legacy_id')->count(),
            DB::table('expense_items')->whereNotNull('legacy_id')->count(),
        ]);
    }

    private function createLegacySchema(): void
    {
        $schema = Schema::connection('legacy_mysql');
        $schema->create('chart_of_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('chart_of_account_name');
            $table->string('chart_of_account_code');
            $table->integer('status')->nullable();
        });
        $schema->create('expenses_type_v2', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('chart_of_account_id');
            $table->string('expense_type');
            $table->string('expense_type_code');
            $table->integer('is_profit')->default(0);
            $table->integer('status')->nullable();
        });
        $schema->create('expenses_category_v2', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('expense_type_id');
            $table->string('expense_category_code');
            $table->string('expense_category_name');
            $table->integer('status')->nullable();
        });
        $schema->create('expenses_v2', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('expense_category_id');
            $table->string('expense_code');
            $table->string('expense_name');
            $table->text('details')->nullable();
            $table->integer('is_hidden')->default(0);
            $table->integer('status')->nullable();
        });
    }

    private function seedLegacyRows(): void
    {
        $legacy = DB::connection('legacy_mysql');
        foreach ([
            [1, 'ASSETS'], [2, 'LIABILITIES'], [3, 'EQUITY'],
            [4, 'REVENUE'], [5, 'COST OF GOODS SOLD'], [6, 'EXPENSES'],
        ] as [$id, $name]) {
            $legacy->table('chart_of_accounts')->insert([
                'id' => $id, 'chart_of_account_name' => $name,
                'chart_of_account_code' => (string) $id, 'status' => 0,
            ]);
        }
        $legacy->table('expenses_type_v2')->insert([
            'id' => 1, 'chart_of_account_id' => 6, 'expense_type' => 'Operating Expense',
            'expense_type_code' => '1', 'is_profit' => 0, 'status' => 0,
        ]);
        $legacy->table('expenses_category_v2')->insert([
            'id' => 10, 'expense_type_id' => 1, 'expense_category_code' => '11',
            'expense_category_name' => 'Bank & Financial Charges', 'status' => null,
        ]);
        $legacy->table('expenses_v2')->insert([
            [
                'id' => 100, 'expense_category_id' => 10, 'expense_code' => '01',
                'expense_name' => 'Bank Service Fee', 'details' => null, 'is_hidden' => 0, 'status' => 0,
            ],
            [
                'id' => 101, 'expense_category_id' => 10, 'expense_code' => '01',
                'expense_name' => 'Hidden Legacy Fee', 'details' => 'Keep for history', 'is_hidden' => 1, 'status' => 0,
            ],
        ]);
    }
}
