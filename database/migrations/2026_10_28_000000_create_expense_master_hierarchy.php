<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_account_groups')) {
            Schema::create('chart_account_groups', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('name')->unique();
                $table->string('normal_balance', 10);
                $table->unsignedSmallInteger('display_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('legacy_source', 80)->nullable();
                $table->unsignedBigInteger('legacy_id')->nullable();
                $table->timestamps();
                $table->unique(['legacy_source', 'legacy_id']);
            });
        }

        if (! Schema::hasColumn('chart_accounts', 'group_id')) {
            Schema::table('chart_accounts', function (Blueprint $table) {
                $table->foreignId('group_id')->nullable()->after('type');
            });
        }
        if (! Schema::hasColumn('chart_accounts', 'is_postable')) {
            Schema::table('chart_accounts', function (Blueprint $table) {
                $table->boolean('is_postable')->default(true)->after('parent_id');
            });
        }
        if (! Schema::hasColumn('chart_accounts', 'display_order')) {
            Schema::table('chart_accounts', function (Blueprint $table) {
                $table->unsignedSmallInteger('display_order')->default(0)->after('is_postable');
            });
        }
        if (! Schema::hasColumn('chart_accounts', 'description')) {
            Schema::table('chart_accounts', function (Blueprint $table) {
                $table->text('description')->nullable()->after('display_order');
            });
        }
        $this->ensureChartAccountGroupConstraintAndIndex();

        if (! Schema::hasTable('expense_types')) {
            Schema::create('expense_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chart_account_group_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('chart_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->boolean('is_profit')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('legacy_source', 80)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->unique(['legacy_source', 'legacy_id']);
            $table->index(['is_active', 'display_order']);
            });
        }

        if (! Schema::hasTable('expense_categories')) {
            Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('legacy_source', 80)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->unique(['legacy_source', 'legacy_id']);
            $table->index(['expense_type_id', 'code']);
            $table->index(['expense_type_id', 'is_active', 'display_order'], 'expense_categories_active_order');
            });
        }

        if (! Schema::hasTable('expense_items')) {
            Schema::create('expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->text('details')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('system_key', 60)->nullable()->unique();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('legacy_source', 80)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->unique(['legacy_source', 'legacy_id']);
            $table->index(['expense_category_id', 'code']);
            $table->index(['expense_category_id', 'is_active', 'is_hidden'], 'expense_items_visibility');
            });
        }

        if (! Schema::hasColumn('expenses', 'expense_item_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreignId('expense_item_id')->nullable()->after('chart_account_id')
                    ->constrained()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->after('description')
                    ->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->after('created_by')
                    ->constrained('users')->nullOnDelete();
                $table->string('approval_status', 20)->default('approved')->after('approved_by');
                $table->timestamp('approved_at')->nullable()->after('approval_status');
                $table->text('approval_notes')->nullable()->after('approved_at');
                $table->boolean('is_received')->default(false)->after('approval_notes');
                $table->date('received_date')->nullable()->after('is_received');
                $table->string('reference', 100)->nullable()->after('received_date');
                $table->string('source_type', 50)->nullable()->after('reference');
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
                $table->boolean('system_generated')->default(false)->after('source_id');
                $table->unique(['source_type', 'source_id'], 'expenses_source_unique');
                $table->index(['expense_item_id', 'expense_date']);
                $table->index(['approval_status', 'status']);
            });
        }

        $this->seedGroupsAndBackfillAccounts();
        $this->seedFinancialExpenseMasters();
    }

    private function ensureChartAccountGroupConstraintAndIndex(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('chart_accounts', function (Blueprint $table) {
                $table->index(['group_id', 'is_active']);
            });

            return;
        }

        $foreignExists = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'chart_accounts')
            ->where('COLUMN_NAME', 'group_id')
            ->where('REFERENCED_TABLE_NAME', 'chart_account_groups')
            ->exists();
        if (! $foreignExists) {
            Schema::table('chart_accounts', function (Blueprint $table) {
                $table->foreign('group_id', 'chart_accounts_group_id_foreign')
                    ->references('id')->on('chart_account_groups')->nullOnDelete();
            });
        }

        $indexExists = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'chart_accounts')
            ->where('INDEX_NAME', 'chart_accounts_group_id_is_active_index')
            ->exists();
        if (! $indexExists) {
            Schema::table('chart_accounts', function (Blueprint $table) {
                $table->index(['group_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::disableForeignKeyConstraints();
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropUnique('expenses_source_unique');
                $table->dropIndex(['expense_item_id', 'expense_date']);
                $table->dropIndex(['approval_status', 'status']);
            });
            Schema::dropIfExists('expense_items');
            Schema::dropIfExists('expense_categories');
            Schema::dropIfExists('expense_types');
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropColumn([
                    'expense_item_id', 'created_by', 'approved_by', 'approval_status',
                    'approved_at', 'approval_notes', 'is_received', 'received_date',
                    'reference', 'source_type', 'source_id', 'system_generated',
                ]);
            });
            Schema::table('chart_accounts', function (Blueprint $table) {
                $table->dropIndex(['group_id', 'is_active']);
                $table->dropColumn(['group_id', 'is_postable', 'display_order', 'description']);
            });
            Schema::dropIfExists('chart_account_groups');
            Schema::enableForeignKeyConstraints();

            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique('expenses_source_unique');
            $table->dropIndex(['expense_item_id', 'expense_date']);
            $table->dropIndex(['approval_status', 'status']);
            $table->dropConstrainedForeignId('expense_item_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'approval_status', 'approved_at', 'approval_notes', 'is_received',
                'received_date', 'reference', 'source_type', 'source_id', 'system_generated',
            ]);
        });

        Schema::dropIfExists('expense_items');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('expense_types');

        Schema::table('chart_accounts', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'is_active']);
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn(['is_postable', 'display_order', 'description']);
        });

        Schema::dropIfExists('chart_account_groups');
    }

    private function seedGroupsAndBackfillAccounts(): void
    {
        $groups = [
            ['code' => 'ASSET', 'name' => 'Assets', 'normal_balance' => 'debit', 'display_order' => 10],
            ['code' => 'LIABILITY', 'name' => 'Liabilities', 'normal_balance' => 'credit', 'display_order' => 20],
            ['code' => 'EQUITY', 'name' => 'Equity', 'normal_balance' => 'credit', 'display_order' => 30],
            ['code' => 'REVENUE', 'name' => 'Revenue', 'normal_balance' => 'credit', 'display_order' => 40],
            ['code' => 'COGS', 'name' => 'Cost of Goods Sold', 'normal_balance' => 'debit', 'display_order' => 50],
            ['code' => 'EXPENSE', 'name' => 'Expenses', 'normal_balance' => 'debit', 'display_order' => 60],
        ];

        foreach ($groups as $index => $group) {
            DB::table('chart_account_groups')->updateOrInsert(
                ['code' => $group['code']],
                $group + [
                    'is_active' => true,
                    'legacy_source' => 'system.chart_of_accounts',
                    'legacy_id' => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $ids = DB::table('chart_account_groups')->pluck('id', 'code');
        foreach (['asset' => 'ASSET', 'liability' => 'LIABILITY', 'equity' => 'EQUITY', 'revenue' => 'REVENUE', 'expense' => 'EXPENSE'] as $type => $group) {
            DB::table('chart_accounts')->where('type', $type)->whereNull('group_id')->update([
                'group_id' => $ids[$group],
                'updated_at' => now(),
            ]);
        }
    }

    private function seedFinancialExpenseMasters(): void
    {
        $expenseGroupId = DB::table('chart_account_groups')->where('code', 'EXPENSE')->value('id');
        $parentId = $this->chartAccount(
            'FINANCING-COSTS',
            'Bank and Financing Costs',
            $expenseGroupId,
            null,
            false,
            100
        );
        $bankFeesId = $this->chartAccount('BANK-FEES', 'Bank and E-Wallet Fees', $expenseGroupId, $parentId, true, 110);
        $cardInterestId = $this->chartAccount('CC-INTEREST-EXPENSE', 'Credit Card Interest Expense', $expenseGroupId, $parentId, true, 120);
        $cardFeesId = $this->chartAccount('CC-FEES-EXPENSE', 'Credit Card Fees', $expenseGroupId, $parentId, true, 130);
        $loanInterestId = $this->chartAccount('LOAN-INTEREST-EXPENSE', 'Loan Interest Expense', $expenseGroupId, $parentId, true, 140);
        $loanOverdueId = $this->chartAccount('LOAN-OVERDUE-INTEREST', 'Loan Overdue Interest Expense', $expenseGroupId, $parentId, true, 150);

        DB::table('expense_types')->updateOrInsert(
            ['code' => 'FINANCIAL_COSTS'],
            [
                'chart_account_group_id' => $expenseGroupId,
                'chart_account_id' => $parentId,
                'name' => 'Bank and Financing Costs',
                'is_profit' => false,
                'display_order' => 100,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $typeId = DB::table('expense_types')->where('code', 'FINANCIAL_COSTS')->value('id');

        $categories = [
            'BANK_FEES' => ['Bank and E-Wallet Charges', $bankFeesId, 10],
            'CREDIT_CARD_FEES' => ['Credit Card Interest and Fees', $cardInterestId, 20],
            'LOAN_COSTS' => ['Loan Interest and Penalties', $loanInterestId, 30],
        ];
        foreach ($categories as $code => [$name, $accountId, $order]) {
            DB::table('expense_categories')->updateOrInsert(
                ['expense_type_id' => $typeId, 'code' => $code],
                [
                    'chart_account_id' => $accountId,
                    'name' => $name,
                    'display_order' => $order,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
        $categoryIds = DB::table('expense_categories')
            ->where('expense_type_id', $typeId)
            ->pluck('id', 'code');

        $items = [
            ['BANK_SERVICE_CHARGE', 'Bank Service Charge', 'BANK_FEES', $bankFeesId, 10],
            ['BANK_TRANSFER_FEE', 'Bank Transfer Fee', 'BANK_FEES', $bankFeesId, 20],
            ['E_WALLET_FEE', 'E-Wallet Transfer Fee', 'BANK_FEES', $bankFeesId, 30],
            ['CREDIT_CARD_INTEREST', 'Credit Card Interest', 'CREDIT_CARD_FEES', $cardInterestId, 10],
            ['CC_MONTHLY_MEMBERSHIP', 'Credit Card Monthly Membership Fee', 'CREDIT_CARD_FEES', $cardFeesId, 20],
            ['CC_ANNUAL_FEE', 'Credit Card Annual Fee', 'CREDIT_CARD_FEES', $cardFeesId, 30],
            ['CC_OVERLIMIT_FEE', 'Credit Card Overlimit Fee', 'CREDIT_CARD_FEES', $cardFeesId, 40],
            ['LOAN_INTEREST', 'Loan Interest Expense', 'LOAN_COSTS', $loanInterestId, 10],
            ['LOAN_OVERDUE_INTEREST', 'Loan Overdue Interest', 'LOAN_COSTS', $loanOverdueId, 20],
        ];
        foreach ($items as [$systemKey, $name, $categoryCode, $accountId, $order]) {
            DB::table('expense_items')->updateOrInsert(
                ['system_key' => $systemKey],
                [
                    'expense_category_id' => $categoryIds[$categoryCode],
                    'chart_account_id' => $accountId,
                    'code' => $systemKey,
                    'name' => $name,
                    'details' => 'System default; may also be selected for manual expense entry.',
                    'is_hidden' => false,
                    'is_active' => true,
                    'display_order' => $order,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function chartAccount(
        string $code,
        string $name,
        int $groupId,
        ?int $parentId,
        bool $postable,
        int $displayOrder,
    ): int {
        $account = DB::table('chart_accounts')->where('code', $code)->first();
        $values = [
            'name' => $name,
            'type' => 'expense',
            'group_id' => $groupId,
            'parent_id' => $parentId,
            'is_postable' => $postable,
            'display_order' => $displayOrder,
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($account) {
            DB::table('chart_accounts')->where('id', $account->id)->update($values);

            return (int) $account->id;
        }

        return (int) DB::table('chart_accounts')->insertGetId($values + [
            'code' => $code,
            'created_at' => now(),
        ]);
    }
};
