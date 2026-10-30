<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_accounts', function (Blueprint $table) {
            $table->string('legacy_source', 80)->nullable()->after('is_active');
            $table->unsignedBigInteger('legacy_id')->nullable()->after('legacy_source');
            $table->unique(['legacy_source', 'legacy_id']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('legacy_source', 80)->nullable()->after('description');
            $table->unsignedBigInteger('legacy_id')->nullable()->after('legacy_source');
            $table->unique(['legacy_source', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique(['legacy_source', 'legacy_id']);
            $table->dropColumn(['legacy_source', 'legacy_id']);
        });

        Schema::table('chart_accounts', function (Blueprint $table) {
            $table->dropUnique(['legacy_source', 'legacy_id']);
            $table->dropColumn(['legacy_source', 'legacy_id']);
        });
    }
};
