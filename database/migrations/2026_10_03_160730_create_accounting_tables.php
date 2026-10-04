<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chart_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->foreignId('parent_id')->nullable()->constrained('chart_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->foreignId('inventory_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('due_from_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('due_to_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('financial_institutions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->enum('type', ['bank', 'e_wallet', 'cash', 'other'])->default('bank');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->enum('type', ['cash', 'bank_transfer', 'cheque', 'credit_card', 'e_wallet', 'other']);
            $table->boolean('creates_obligation')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chart_account_id')->unique()->constrained()->restrictOnDelete();
            $table->enum('kind', ['cash', 'bank_account', 'e_wallet', 'checking_account', 'credit_card']);
            $table->string('name');
            $table->text('account_number')->nullable()->comment('Encrypted at the application layer');
            $table->char('account_number_hash', 64)->nullable()->unique();
            $table->string('account_number_last4', 4)->nullable();
            $table->string('account_holder')->nullable();
            $table->string('description')->nullable();
            $table->string('currency', 3)->default('PHP');
            $table->decimal('credit_limit', 19, 4)->nullable();
            $table->unsignedTinyInteger('statement_day')->nullable();
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->unsignedSmallInteger('buffer_days')->default(0);
            $table->boolean('usable_for_receipts')->default(false);
            $table->boolean('usable_for_payments')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('legacy_source', 50)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->unique(['legacy_source', 'legacy_id']);
        });

        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['customer', 'supplier']);
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('number', 50)->unique();
            $table->foreignId('supplier_id')->constrained('counterparties')->restrictOnDelete();
            $table->date('order_date');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('total_amount', 19, 4);
            $table->enum('status', ['open', 'partially_paid', 'paid', 'cancelled'])->default('open');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('number', 50)->unique();
            $table->foreignId('payee_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->date('expense_date');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('amount', 19, 4);
            $table->enum('status', ['unpaid', 'partially_paid', 'paid', 'cancelled'])->default('unpaid');
            $table->text('description');
            $table->timestamps();
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->string('number', 50)->unique();
            $table->date('invoice_date');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('total_amount', 19, 4);
            $table->enum('status', ['unpaid', 'partially_paid', 'paid', 'cancelled'])->default('unpaid');
            $table->string('legacy_source', 50)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['legacy_source', 'legacy_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('number', 50)->unique();
            $table->enum('type', ['customer', 'purchase_order', 'expense', 'chart_account']);
            $table->enum('direction', ['inflow', 'outflow']);
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('offset_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->date('payment_date');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('amount', 19, 4);
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->enum('status', ['draft', 'posted', 'voided'])->default('draft');
            $table->text('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['type', 'status', 'payment_date']);
        });

        Schema::create('payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->unsignedSmallInteger('installment_count')->default(1);
            $table->string('reference', 100)->nullable();
            $table->string('legacy_source', 50)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->unique(['legacy_source', 'legacy_id']);
        });

        Schema::create('payment_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 60)->unique();
            $table->enum('type', ['credit_card_statement', 'issued_cheque', 'purchase_order', 'expense', 'ibo', 'other']);
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('offset_account_id')->nullable()->constrained('chart_accounts')->nullOnDelete();
            $table->date('statement_date')->nullable();
            $table->date('due_date');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('principal_amount', 19, 4);
            $table->decimal('interest_amount', 19, 4)->default(0);
            $table->decimal('fee_amount', 19, 4)->default(0);
            $table->decimal('minimum_due', 19, 4)->default(0);
            $table->enum('status', ['open', 'partially_paid', 'paid', 'overdue', 'voided'])->default('open');
            $table->string('source_reference')->nullable();
            $table->string('legacy_source', 50)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['legacy_source', 'legacy_id']);
            $table->unique(['type', 'financial_account_id', 'statement_date'], 'payment_obligation_cycle_unique');
            $table->index(['type', 'status', 'due_date']);
        });

        Schema::create('obligation_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_obligation_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_line_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('installment_number')->default(1);
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['payment_line_id', 'installment_number']);
            $table->unique(['payment_obligation_id', 'payment_line_id']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_obligation_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['payment_id', 'payment_obligation_id']);
        });

        Schema::create('interbranch_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('source_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('order_date');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('amount', 19, 4);
            $table->enum('settlement_mode', ['inventory_transfer', 'internal_clearing'])->default('internal_clearing');
            $table->enum('status', ['draft', 'posted', 'voided'])->default('draft');
            $table->text('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('interbranch_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('kind', ['payment', 'reversal', 'interbranch_transfer', 'interbranch_reversal', 'end_of_day_settlement', 'end_of_day_reversal', 'account_transfer', 'account_transfer_reversal', 'account_balance_adjustment', 'account_balance_adjustment_reversal']);
            $table->string('number', 60)->unique();
            $table->date('entry_date');
            $table->text('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('interbranch_orders');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('obligation_charges');
        Schema::dropIfExists('payment_obligations');
        Schema::dropIfExists('payment_lines');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('counterparties');
        Schema::dropIfExists('financial_accounts');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('financial_institutions');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('chart_accounts');
    }
};
