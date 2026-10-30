<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('financial_institution_id')
                ->nullable()
                ->after('borrower_type')
                ->constrained('financial_institutions')
                ->restrictOnDelete();
            $table->foreignId('user_account_id')
                ->nullable()
                ->after('financial_institution_id')
                ->constrained('user_accounts')
                ->restrictOnDelete();
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `loans` MODIFY `financial_account_id` BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_account_id');
            $table->dropConstrainedForeignId('financial_institution_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `loans` MODIFY `financial_account_id` BIGINT UNSIGNED NOT NULL');
        }
    }
};
