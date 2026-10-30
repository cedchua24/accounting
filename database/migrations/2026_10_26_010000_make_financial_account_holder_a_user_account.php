<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        $temporaryColumn = 'account_holder_user_account_id';
        $hasLegacyHolder = Schema::hasColumn('financial_accounts', 'account_holder');
        $hasLegacyUserAccount = Schema::hasColumn('financial_accounts', 'user_account_id');

        if (! Schema::hasColumn('financial_accounts', $temporaryColumn)) {
            Schema::table('financial_accounts', function (Blueprint $table) use ($temporaryColumn) {
                $table->unsignedBigInteger($temporaryColumn)->nullable();
            });
        }

        // The column checks make this migration safe to resume after a partial
        // MariaDB DDL failure (DDL statements are not transactional there).
        if ($hasLegacyHolder || $hasLegacyUserAccount) {
            $holders = DB::table('user_accounts')->get(['id', 'name'])
                ->keyBy(fn ($holder) => $this->nameKey($holder->name));

            DB::table('financial_accounts')->orderBy('id')->each(
                function ($account) use ($holders, $hasLegacyHolder, $hasLegacyUserAccount, $temporaryColumn) {
                    $holderId = $hasLegacyUserAccount ? $account->user_account_id : null;
                    $holderName = $hasLegacyHolder ? trim((string) $account->account_holder) : '';

                    if (! $holderId && $holderName !== '') {
                        $key = $this->nameKey($holderName);
                        $holder = $holders->get($key);
                        if (! $holder) {
                            $holderId = DB::table('user_accounts')->insertGetId([
                                'code' => 'HOLDER-'.strtoupper(substr(sha1($key), 0, 12)),
                                'type' => str_contains(strtoupper($holderName), 'TRADING') ? 'company' : 'individual',
                                'name' => $holderName,
                                'is_active' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $holder = (object) ['id' => $holderId, 'name' => $holderName];
                            $holders->put($key, $holder);
                        } else {
                            $holderId = $holder->id;
                        }
                    }

                    DB::table('financial_accounts')->where('id', $account->id)
                        ->update([$temporaryColumn => $holderId]);
                }
            );
        }

        if ($hasLegacyUserAccount) {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_account_id');
            });
        }
        if ($hasLegacyHolder) {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->dropColumn('account_holder');
            });
        }

        if (! Schema::hasColumn('financial_accounts', 'account_holder')) {
            DB::statement(
                'ALTER TABLE `financial_accounts` CHANGE `account_holder_user_account_id` `account_holder` BIGINT UNSIGNED NULL'
            );
        }

        if (! $this->foreignKeyExists('financial_accounts_account_holder_foreign')) {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->foreign('account_holder')->references('id')->on('user_accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasColumn('financial_accounts', 'account_holder_name')) {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->string('account_holder_name')->nullable();
            });
        }
        if (! Schema::hasColumn('financial_accounts', 'user_account_id')) {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->unsignedBigInteger('user_account_id')->nullable();
            });
        }

        DB::table('financial_accounts')
            ->leftJoin('user_accounts', 'user_accounts.id', '=', 'financial_accounts.account_holder')
            ->select('financial_accounts.id', 'financial_accounts.account_holder', 'user_accounts.name')
            ->orderBy('financial_accounts.id')
            ->each(function ($account) {
                DB::table('financial_accounts')->where('id', $account->id)->update([
                    'user_account_id' => $account->account_holder,
                    'account_holder_name' => $account->name,
                ]);
            });
        if ($this->foreignKeyExists('financial_accounts_account_holder_foreign')) {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->dropForeign(['account_holder']);
            });
        }
        if (Schema::hasColumn('financial_accounts', 'account_holder')) {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->dropColumn('account_holder');
            });
        }

        DB::statement(
            'ALTER TABLE `financial_accounts` CHANGE `account_holder_name` `account_holder` VARCHAR(255) NULL'
        );

        if (! $this->foreignKeyExists('financial_accounts_user_account_id_foreign')) {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->foreign('user_account_id')->references('id')->on('user_accounts')->nullOnDelete();
            });
        }
    }

    private function nameKey(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim($name)));
    }

    private function foreignKeyExists(string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'financial_accounts')
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
