<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->enum('type', ['individual', 'company'])->default('individual');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('tax_identifier', 80)->nullable();
            $table->json('details')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['type', 'is_active']);
            $table->index(['user_id', 'is_active']);
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->foreignId('user_account_id')->nullable()->after('owner_branch_id')
                    ->constrained('user_accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_account_id');
            });
        }
        Schema::dropIfExists('user_accounts');
    }
};
