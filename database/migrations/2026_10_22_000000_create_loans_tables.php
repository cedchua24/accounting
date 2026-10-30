<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('number', 60)->unique();
            $table->enum('direction', ['borrowed', 'lent'])->default('lent');
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('party_name')->nullable();
            $table->foreignId('financial_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('first_due_date');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('principal_amount', 19, 4);
            $table->decimal('total_interest_amount', 19, 4)->default(0);
            $table->unsignedSmallInteger('term_months');
            $table->decimal('overdue_interest_rate', 9, 4)->default(0)
                ->comment('Simple percentage charged per overdue_interest_period');
            $table->enum('overdue_interest_period', ['daily', 'monthly', 'annual'])->default('annual');
            $table->enum('status', ['active', 'paid', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'first_due_date']);
            $table->index(['counterparty_id', 'status']);
        });

        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date');
            $table->decimal('principal_due', 19, 4);
            $table->decimal('interest_due', 19, 4)->default(0);
            $table->timestamps();
            $table->unique(['loan_id', 'installment_number']);
            $table->index(['loan_id', 'due_date']);
        });

        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->enum('excess_treatment', ['overdue_interest', 'overpayment'])->default('overdue_interest');
            $table->decimal('overpayment_amount', 19, 4)->default(0);
            $table->date('payment_date');
            $table->decimal('amount', 19, 4);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['loan_id', 'payment_date']);
        });

        Schema::create('loan_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_installment_id')->constrained()->restrictOnDelete();
            $table->decimal('principal_amount', 19, 4)->default(0);
            $table->decimal('interest_amount', 19, 4)->default(0);
            $table->decimal('overdue_interest_amount', 19, 4)->default(0);
            $table->decimal('overdue_interest_waived_amount', 19, 4)->default(0);
            $table->timestamps();
            $table->unique(['loan_payment_id', 'loan_installment_id'], 'loan_payment_installment_unique');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('loan_payment_id')->nullable()->after('payment_id')
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('journal_entries', 'loan_payment_id')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropConstrainedForeignId('loan_payment_id');
            });
        }
        Schema::dropIfExists('loan_payment_allocations');
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loans');
    }
};
