<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->boolean('is_company_wide')->default(false)->after('owner_branch_id');
        });

        Schema::create('financial_account_branch_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['financial_account_id', 'branch_id'], 'financial_account_branch_unique');
            $table->index('branch_id', 'financial_account_branch_index');
        });

        DB::table('financial_accounts')->whereNull('owner_branch_id')->update(['is_company_wide' => true]);
        DB::table('financial_accounts')->whereNotNull('owner_branch_id')->orderBy('id')->each(function ($account) {
            DB::table('financial_account_branch_access')->insertOrIgnore([
                'financial_account_id' => $account->id,
                'branch_id' => $account->owner_branch_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_branch_access');
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropColumn('is_company_wide');
        });
    }
};
