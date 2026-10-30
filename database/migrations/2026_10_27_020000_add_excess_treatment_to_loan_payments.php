<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('loan_payments', 'excess_treatment')) {
            Schema::table('loan_payments', function (Blueprint $table) {
                $table->enum('excess_treatment', ['overdue_interest', 'overpayment'])
                    ->default('overdue_interest')
                    ->after('financial_account_id');
            });
        }
        if (! Schema::hasColumn('loan_payments', 'overpayment_amount')) {
            Schema::table('loan_payments', function (Blueprint $table) {
                $table->decimal('overpayment_amount', 19, 4)
                    ->default(0)
                    ->after('excess_treatment');
            });
        }
        if (! Schema::hasColumn('loan_payment_allocations', 'overdue_interest_waived_amount')) {
            Schema::table('loan_payment_allocations', function (Blueprint $table) {
                $table->decimal('overdue_interest_waived_amount', 19, 4)
                    ->default(0)
                    ->after('overdue_interest_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('loan_payment_allocations', 'overdue_interest_waived_amount')) {
            Schema::table('loan_payment_allocations', function (Blueprint $table) {
                $table->dropColumn('overdue_interest_waived_amount');
            });
        }
        if (Schema::hasColumn('loan_payments', 'overpayment_amount')) {
            Schema::table('loan_payments', function (Blueprint $table) {
                $table->dropColumn('overpayment_amount');
            });
        }
        if (Schema::hasColumn('loan_payments', 'excess_treatment')) {
            Schema::table('loan_payments', function (Blueprint $table) {
                $table->dropColumn('excess_treatment');
            });
        }
    }
};
