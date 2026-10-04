<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_account_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('source_financial_account_id');
            $table->foreignId('destination_financial_account_id');
            $table->date('transfer_date');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('amount', 19, 4);
            $table->string('reference', 100)->nullable();
            $table->text('memo')->nullable();
            $table->enum('status', ['draft', 'posted', 'voided'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['transfer_date', 'status']);
            $table->foreign('source_financial_account_id', 'account_transfer_source_fk')
                ->references('id')->on('financial_accounts')->restrictOnDelete();
            $table->foreign('destination_financial_account_id', 'account_transfer_destination_fk')
                ->references('id')->on('financial_accounts')->restrictOnDelete();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('financial_account_transfer_id')->nullable()->after('end_of_day_settlement_id')
                ->constrained()->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journal_entries MODIFY kind ENUM('payment','reversal','interbranch_transfer','interbranch_reversal','end_of_day_settlement','end_of_day_reversal','account_transfer','account_transfer_reversal') NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_account_transfer_id');
        });
        Schema::dropIfExists('financial_account_transfers');

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journal_entries MODIFY kind ENUM('payment','reversal','interbranch_transfer','interbranch_reversal','end_of_day_settlement','end_of_day_reversal') NOT NULL");
        }
    }
};
