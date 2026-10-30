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
            $table->decimal('current_balance', 19, 4)->default(0)->after('credit_limit');
            $table->decimal('available_balance', 19, 4)->default(0)->after('current_balance');
            $table->timestamp('balance_updated_at')->nullable()->after('available_balance');
        });

        DB::table('financial_accounts')->orderBy('id')->each(function ($account) {
            $net = (string) DB::table('journal_lines')
                ->where('chart_account_id', $account->chart_account_id)
                ->selectRaw('COALESCE(SUM(debit - credit), 0) AS balance')
                ->value('balance');
            $current = $account->kind === 'credit_card' ? bcsub('0', $net, 4) : bcadd($net, '0', 4);
            $available = $account->kind === 'credit_card'
                ? bcsub((string) ($account->credit_limit ?? 0), $current, 4)
                : $current;

            DB::table('financial_accounts')->where('id', $account->id)->update([
                'current_balance' => $current,
                'available_balance' => $available,
                'balance_updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropColumn(['current_balance', 'available_balance', 'balance_updated_at']);
        });
    }
};
