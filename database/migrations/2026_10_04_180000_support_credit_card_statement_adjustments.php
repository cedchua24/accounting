<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_obligations', function (Blueprint $table) {
            $table->decimal('adjustment_amount', 19, 4)->default(0)->after('fee_amount');
        });
        Schema::table('financial_account_adjustments', function (Blueprint $table) {
            $table->foreignId('payment_obligation_id')->nullable()->after('financial_account_id')
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_account_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_obligation_id');
        });
        Schema::table('payment_obligations', function (Blueprint $table) {
            $table->dropColumn('adjustment_amount');
        });
    }
};
