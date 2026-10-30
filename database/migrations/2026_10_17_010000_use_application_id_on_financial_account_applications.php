<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('financial_account_applications', 'application_id')) {
            Schema::table('financial_account_applications', function (Blueprint $table) {
                $table->unsignedBigInteger('application_id')->nullable()->after('financial_account_id');
            });
        }

        if (Schema::hasColumn('financial_account_applications', 'application')) {
            DB::table('financial_account_application_types')->get(['id', 'code'])->each(function ($type) {
                DB::table('financial_account_applications')
                    ->where('application', $type->code)
                    ->update(['application_id' => $type->id]);
            });
        }

        if (DB::table('financial_account_applications')->whereNull('application_id')->exists()) {
            throw new \RuntimeException('Some financial-account applications have no matching application type.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTableWithApplicationId();

            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $this->migrateMysqlTableToApplicationId();

            return;
        }

        Schema::table('financial_account_applications', function (Blueprint $table) {
            $table->dropForeign('financial_account_application_type_fk');
            $table->dropUnique('financial_account_application_unique');
            $table->dropIndex('financial_account_application_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE financial_account_applications MODIFY application_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('financial_account_applications', function (Blueprint $table) {
            $table->dropColumn('application');
        });

        Schema::table('financial_account_applications', function (Blueprint $table) {
            $table->foreign('application_id', 'financial_account_application_type_id_fk')
                ->references('id')
                ->on('financial_account_application_types')
                ->restrictOnDelete();
            $table->unique(['financial_account_id', 'application_id'], 'financial_account_application_unique');
            $table->index('application_id', 'financial_account_application_index');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTableWithApplicationCode();

            return;
        }

        Schema::table('financial_account_applications', function (Blueprint $table) {
            $table->string('application', 40)->nullable()->after('financial_account_id');
        });

        DB::table('financial_account_application_types')->get(['id', 'code'])->each(function ($type) {
            DB::table('financial_account_applications')
                ->where('application_id', $type->id)
                ->update(['application' => $type->code]);
        });

        Schema::table('financial_account_applications', function (Blueprint $table) {
            $table->dropForeign('financial_account_application_type_id_fk');
            $table->dropUnique('financial_account_application_unique');
            $table->dropIndex('financial_account_application_index');
            $table->dropColumn('application_id');
        });

        Schema::table('financial_account_applications', function (Blueprint $table) {
            $table->foreign('application', 'financial_account_application_type_fk')
                ->references('code')
                ->on('financial_account_application_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->unique(['financial_account_id', 'application'], 'financial_account_application_unique');
            $table->index('application', 'financial_account_application_index');
        });
    }

    private function rebuildSqliteTableWithApplicationId(): void
    {
        Schema::create('financial_account_applications_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('financial_account_application_types')->restrictOnDelete();
            $table->string('source', 40)->default('manual');
            $table->timestamps();
        });

        DB::table('financial_account_applications_new')->insertUsing(
            ['id', 'financial_account_id', 'application_id', 'source', 'created_at', 'updated_at'],
            DB::table('financial_account_applications')->select(
                'id', 'financial_account_id', 'application_id', 'source', 'created_at', 'updated_at'
            )
        );

        Schema::drop('financial_account_applications');
        Schema::rename('financial_account_applications_new', 'financial_account_applications');
        Schema::table('financial_account_applications', function (Blueprint $table) {
            $table->unique(['financial_account_id', 'application_id'], 'financial_account_application_unique');
            $table->index('application_id', 'financial_account_application_index');
        });
    }

    private function migrateMysqlTableToApplicationId(): void
    {
        if (! $this->mysqlIndexExists('financial_account_applications_financial_account_id_index')) {
            DB::statement('ALTER TABLE financial_account_applications ADD INDEX financial_account_applications_financial_account_id_index (financial_account_id)');
        }
        if ($this->mysqlForeignKeyExists('financial_account_application_type_fk')) {
            DB::statement('ALTER TABLE financial_account_applications DROP FOREIGN KEY financial_account_application_type_fk');
        }
        if ($this->mysqlIndexExists('financial_account_application_unique')) {
            DB::statement('ALTER TABLE financial_account_applications DROP INDEX financial_account_application_unique');
        }
        if ($this->mysqlIndexExists('financial_account_application_index')) {
            DB::statement('ALTER TABLE financial_account_applications DROP INDEX financial_account_application_index');
        }

        DB::statement('ALTER TABLE financial_account_applications MODIFY application_id BIGINT UNSIGNED NOT NULL');

        if (Schema::hasColumn('financial_account_applications', 'application')) {
            Schema::table('financial_account_applications', function (Blueprint $table) {
                $table->dropColumn('application');
            });
        }

        if (! $this->mysqlIndexExists('financial_account_application_unique')) {
            DB::statement('ALTER TABLE financial_account_applications ADD UNIQUE INDEX financial_account_application_unique (financial_account_id, application_id)');
        }
        if (! $this->mysqlIndexExists('financial_account_application_index')) {
            DB::statement('ALTER TABLE financial_account_applications ADD INDEX financial_account_application_index (application_id)');
        }
        if (! $this->mysqlForeignKeyExists('financial_account_application_type_id_fk')) {
            DB::statement('ALTER TABLE financial_account_applications ADD CONSTRAINT financial_account_application_type_id_fk FOREIGN KEY (application_id) REFERENCES financial_account_application_types (id) ON DELETE RESTRICT');
        }
    }

    private function mysqlIndexExists(string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'financial_account_applications')
            ->where('index_name', $name)
            ->exists();
    }

    private function mysqlForeignKeyExists(string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', 'financial_account_applications')
            ->where('constraint_name', $name)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }

    private function rebuildSqliteTableWithApplicationCode(): void
    {
        Schema::create('financial_account_applications_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained()->cascadeOnDelete();
            $table->string('application', 40);
            $table->string('source', 40)->default('manual');
            $table->timestamps();
            $table->foreign('application', 'financial_account_application_type_fk')
                ->references('code')
                ->on('financial_account_application_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

        DB::table('financial_account_applications_old')->insertUsing(
            ['id', 'financial_account_id', 'application', 'source', 'created_at', 'updated_at'],
            DB::table('financial_account_applications as assignments')
                ->join('financial_account_application_types as types', 'types.id', '=', 'assignments.application_id')
                ->select(
                    'assignments.id', 'assignments.financial_account_id', 'types.code',
                    'assignments.source', 'assignments.created_at', 'assignments.updated_at'
                )
        );

        Schema::drop('financial_account_applications');
        Schema::rename('financial_account_applications_old', 'financial_account_applications');
        Schema::table('financial_account_applications', function (Blueprint $table) {
            $table->unique(['financial_account_id', 'application'], 'financial_account_application_unique');
            $table->index('application', 'financial_account_application_index');
        });
    }
};
