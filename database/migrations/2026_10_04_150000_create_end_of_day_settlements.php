<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('end_of_day_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->date('business_date');
            $table->foreignId('destination_financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->string('currency', 3)->default('PHP');
            $table->decimal('gross_collection_amount', 19, 4)->default(0);
            $table->decimal('transfer_amount', 19, 4)->default(0);
            $table->enum('status', ['draft', 'posted', 'voided'])->default('draft');
            $table->text('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['business_date', 'status'], 'eod_date_status_index');
        });

        Schema::create('end_of_day_settlement_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('end_of_day_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['end_of_day_settlement_id', 'branch_id'], 'eod_settlement_branch_unique');
        });

        Schema::create('end_of_day_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('end_of_day_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->decimal('transfer_amount', 19, 4);
            $table->timestamps();
            $table->unique(['end_of_day_settlement_id', 'payment_line_id'], 'eod_settlement_payment_line_unique');
            $table->index('payment_line_id', 'eod_payment_line_index');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('end_of_day_settlement_id')->nullable()->after('interbranch_order_id')
                ->constrained()->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journal_entries MODIFY kind ENUM('payment','reversal','interbranch_transfer','interbranch_reversal','end_of_day_settlement','end_of_day_reversal') NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('end_of_day_settlement_id');
        });
        Schema::dropIfExists('end_of_day_settlement_items');
        Schema::dropIfExists('end_of_day_settlement_branches');
        Schema::dropIfExists('end_of_day_settlements');

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journal_entries MODIFY kind ENUM('payment','reversal','interbranch_transfer','interbranch_reversal') NOT NULL");
        }
    }
};
