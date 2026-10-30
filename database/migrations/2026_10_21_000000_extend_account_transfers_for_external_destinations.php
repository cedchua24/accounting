<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->makeDestinationNullable();

        Schema::table('financial_account_transfers', function (Blueprint $table) {
            $table->string('destination_type', 20)->default('internal')->after('destination_financial_account_id');
            $table->string('transfer_method', 20)->default('online')->after('destination_type');
            $table->foreignId('counterparty_id')->nullable()->after('transfer_method')->constrained()->nullOnDelete();
            $table->foreignId('offset_account_id')->nullable()->after('counterparty_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->string('recipient_name')->nullable()->after('offset_account_id');
            $table->string('recipient_type', 20)->nullable()->after('recipient_name');
            $table->string('external_institution_name')->nullable()->after('recipient_type');
            $table->string('external_account_name')->nullable()->after('external_institution_name');
            $table->text('external_account_number')->nullable()->after('external_account_name');
            $table->char('external_account_number_hash', 64)->nullable()->after('external_account_number');
            $table->string('external_account_number_last4', 4)->nullable()->after('external_account_number_hash');
            $table->string('recipient_contact', 100)->nullable()->after('external_account_number_last4');
            $table->string('cheque_number', 100)->nullable()->after('recipient_contact');
            $table->uuid('idempotency_key')->nullable()->unique()->after('cheque_number');
            $table->index(['destination_type', 'transfer_method'], 'account_transfer_destination_method_index');
        });
    }

    public function down(): void
    {
        Schema::table('financial_account_transfers', function (Blueprint $table) {
            $table->dropIndex('account_transfer_destination_method_index');
            $table->dropUnique(['idempotency_key']);
            $table->dropConstrainedForeignId('counterparty_id');
            $table->dropConstrainedForeignId('offset_account_id');
            $table->dropColumn([
                'destination_type', 'transfer_method', 'recipient_name', 'recipient_type',
                'external_institution_name', 'external_account_name', 'external_account_number',
                'external_account_number_hash', 'external_account_number_last4', 'recipient_contact',
                'cheque_number', 'idempotency_key',
            ]);
        });

        $this->makeDestinationRequired();
    }

    private function makeDestinationNullable(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement(
                'ALTER TABLE financial_account_transfers MODIFY destination_financial_account_id BIGINT UNSIGNED NULL'
            ),
            'pgsql' => DB::statement(
                'ALTER TABLE financial_account_transfers ALTER COLUMN destination_financial_account_id DROP NOT NULL'
            ),
            // Fresh SQLite schemas already use the nullable definition in the create migration.
            'sqlite' => null,
            default => Schema::table('financial_account_transfers', function (Blueprint $table) {
                $table->foreignId('destination_financial_account_id')->nullable()->change();
            }),
        };
    }

    private function makeDestinationRequired(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement(
                'ALTER TABLE financial_account_transfers MODIFY destination_financial_account_id BIGINT UNSIGNED NOT NULL'
            ),
            'pgsql' => DB::statement(
                'ALTER TABLE financial_account_transfers ALTER COLUMN destination_financial_account_id SET NOT NULL'
            ),
            'sqlite' => null,
            default => Schema::table('financial_account_transfers', function (Blueprint $table) {
                $table->foreignId('destination_financial_account_id')->nullable(false)->change();
            }),
        };
    }
};
