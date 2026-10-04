<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('legacy_source', 50)->nullable()->after('is_active');
            $table->unsignedBigInteger('legacy_id')->nullable()->after('legacy_source');
            $table->unique(['legacy_source', 'legacy_id'], 'branch_legacy_identity_unique');
        });
        Schema::table('counterparties', function (Blueprint $table) {
            $table->string('legacy_source', 50)->nullable()->after('is_active');
            $table->unsignedBigInteger('legacy_id')->nullable()->after('legacy_source');
            $table->unique(['legacy_source', 'legacy_id'], 'counterparty_legacy_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('counterparties', function (Blueprint $table) {
            $table->dropUnique('counterparty_legacy_identity_unique');
            $table->dropColumn(['legacy_source', 'legacy_id']);
        });
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branch_legacy_identity_unique');
            $table->dropColumn(['legacy_source', 'legacy_id']);
        });
    }
};
