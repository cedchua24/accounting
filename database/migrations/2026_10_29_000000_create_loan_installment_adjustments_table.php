<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_installment_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_installment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('old_due_date');
            $table->date('new_due_date');
            $table->decimal('old_principal_due', 19, 4);
            $table->decimal('new_principal_due', 19, 4);
            $table->decimal('old_interest_due', 19, 4);
            $table->decimal('new_interest_due', 19, 4);
            $table->text('reason');
            $table->timestamps();
            $table->index(['loan_id', 'created_at']);
            $table->index(['loan_installment_id', 'created_at'], 'loan_installment_adjustments_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installment_adjustments');
    }
};
