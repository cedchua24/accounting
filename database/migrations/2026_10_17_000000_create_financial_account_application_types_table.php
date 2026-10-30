<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_account_application_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('financial_account_application_types')->insert([
            ['code' => 'customer_receipt', 'name' => 'Customer receipts', 'description' => 'Receive customer and order payments', 'display_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'purchase_order_payment', 'name' => 'Purchase order payments', 'description' => 'Pay suppliers and purchase orders', 'display_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'expense_payment', 'name' => 'Expense payments', 'description' => 'Pay employee and operating expenses', 'display_order' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'interbranch_settlement', 'name' => 'Interbranch settlements', 'description' => 'Settle balances between branches', 'display_order' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'end_of_day_settlement', 'name' => 'End-of-day settlements', 'description' => 'Receive daily branch remittances', 'display_order' => 50, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'account_transfer', 'name' => 'Account transfers', 'description' => 'Move funds between financial accounts', 'display_order' => 60, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'balance_adjustment', 'name' => 'Balance adjustments', 'description' => 'Post audited reconciliation adjustments', 'display_order' => 70, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'obligation_payment', 'name' => 'Obligation payments', 'description' => 'Pay cards, cheques, and other obligations', 'display_order' => 80, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'direct_payment', 'name' => 'Direct payments', 'description' => 'Post payments directly to a ledger account', 'display_order' => 90, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'unclassified', 'name' => 'Unclassified', 'description' => 'Legacy account awaiting manual classification', 'display_order' => 100, 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('financial_account_applications', function (Blueprint $table) {
            $table->foreign('application', 'financial_account_application_type_fk')
                ->references('code')
                ->on('financial_account_application_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_account_applications', function (Blueprint $table) {
            $table->dropForeign('financial_account_application_type_fk');
        });

        Schema::dropIfExists('financial_account_application_types');
    }
};
