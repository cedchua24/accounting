<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_institutions', function (Blueprint $table) {
            $table->string('legacy_source', 50)->nullable()->after('is_active');
            $table->unsignedBigInteger('legacy_id')->nullable()->after('legacy_source');
            $table->unique(['legacy_source', 'legacy_id']);
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropUnique(['account_number_hash']);
            $table->index('account_number_hash');
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropIndex(['account_number_hash']);
            $table->unique('account_number_hash');
        });

        Schema::table('financial_institutions', function (Blueprint $table) {
            $table->dropUnique(['legacy_source', 'legacy_id']);
            $table->dropColumn(['legacy_source', 'legacy_id']);
        });
    }
};
