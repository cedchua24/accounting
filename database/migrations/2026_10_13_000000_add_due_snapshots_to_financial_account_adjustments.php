<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_account_adjustments', function (Blueprint $table) {
            $table->decimal('expected_amount_due_before', 19, 4)->nullable()->after('adjustment_amount');
            $table->decimal('paid_amount_before', 19, 4)->nullable()->after('expected_amount_due_before');
            $table->decimal('actual_amount_due', 19, 4)->nullable()->after('paid_amount_before');
        });
    }

    public function down(): void
    {
        Schema::table('financial_account_adjustments', function (Blueprint $table) {
            $table->dropColumn(['expected_amount_due_before', 'paid_amount_before', 'actual_amount_due']);
        });
    }
};
