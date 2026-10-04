<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_account_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('financial_account_id');
            $table->foreignId('adjustment_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->date('adjustment_date');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('system_balance_before', 19, 4)->nullable();
            $table->decimal('actual_balance', 19, 4);
            $table->decimal('adjustment_amount', 19, 4)->nullable()->comment('Actual balance minus system balance');
            $table->string('reference', 100)->nullable();
            $table->text('reason');
            $table->enum('status', ['draft', 'posted', 'voided'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['adjustment_date', 'status']);
            $table->foreign('financial_account_id', 'account_adjustment_financial_account_fk')
                ->references('id')->on('financial_accounts')->restrictOnDelete();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('financial_account_adjustment_id')->nullable()->after('financial_account_transfer_id')
                ->constrained()->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journal_entries MODIFY kind ENUM('payment','reversal','interbranch_transfer','interbranch_reversal','end_of_day_settlement','end_of_day_reversal','account_transfer','account_transfer_reversal','account_balance_adjustment','account_balance_adjustment_reversal') NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_account_adjustment_id');
        });
        Schema::dropIfExists('financial_account_adjustments');

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journal_entries MODIFY kind ENUM('payment','reversal','interbranch_transfer','interbranch_reversal','end_of_day_settlement','end_of_day_reversal','account_transfer','account_transfer_reversal') NOT NULL");
        }
    }
};
