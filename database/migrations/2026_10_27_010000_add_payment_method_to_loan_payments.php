<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('loan_payments', 'payment_method_id')) {
            return;
        }

        Schema::table('loan_payments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')
                ->nullable()
                ->after('loan_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('loan_payments', 'payment_method_id')) {
            return;
        }

        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};
