<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('original_payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('refund_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->date('refund_date');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('amount', 19, 4);
            $table->string('reference', 100)->nullable();
            $table->text('reason');
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->enum('status', ['draft', 'posted', 'voided'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['sales_invoice_id', 'status']);
            $table->index(['refund_date', 'status']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('customer_refund_id')->nullable()->after('financial_account_adjustment_id')
                ->constrained()->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journal_entries MODIFY kind ENUM('payment','reversal','interbranch_transfer','interbranch_reversal','end_of_day_settlement','end_of_day_reversal','account_transfer','account_transfer_reversal','account_balance_adjustment','account_balance_adjustment_reversal','customer_refund','customer_refund_reversal') NOT NULL");
            DB::statement("ALTER TABLE sales_invoices MODIFY status ENUM('unpaid','partially_paid','paid','partially_refunded','refunded','cancelled') NOT NULL DEFAULT 'unpaid'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE sales_invoices SET status = 'paid' WHERE status IN ('partially_refunded','refunded')");
        }

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_refund_id');
        });
        Schema::dropIfExists('customer_refunds');

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journal_entries MODIFY kind ENUM('payment','reversal','interbranch_transfer','interbranch_reversal','end_of_day_settlement','end_of_day_reversal','account_transfer','account_transfer_reversal','account_balance_adjustment','account_balance_adjustment_reversal') NOT NULL");
            DB::statement("ALTER TABLE sales_invoices MODIFY status ENUM('unpaid','partially_paid','paid','cancelled') NOT NULL DEFAULT 'unpaid'");
        }
    }
};
