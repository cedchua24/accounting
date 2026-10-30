<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_lines', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('reference');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->index(['financial_account_id', 'confirmed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_lines', function (Blueprint $table) {
            $table->dropIndex(['financial_account_id', 'confirmed_at']);
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('confirmed_at');
        });
    }
};
