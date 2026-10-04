<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_account_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained()->cascadeOnDelete();
            $table->string('application', 40);
            $table->string('source', 40)->default('manual');
            $table->timestamps();
            $table->unique(['financial_account_id', 'application'], 'financial_account_application_unique');
            $table->index('application', 'financial_account_application_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_applications');
    }
};
