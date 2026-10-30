<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_entity_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 80);
            $table->string('legacy_table', 80);
            $table->unsignedBigInteger('legacy_id');
            $table->string('central_type');
            $table->unsignedBigInteger('central_id');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_system', 'legacy_table', 'legacy_id'],
                'legacy_entity_source_identity_unique'
            );
            $table->index(['central_type', 'central_id'], 'legacy_entity_central_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_entity_mappings');
    }
};
