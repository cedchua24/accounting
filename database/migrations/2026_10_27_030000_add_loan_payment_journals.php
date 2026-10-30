<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('journal_entries', 'loan_payment_id')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->foreignId('loan_payment_id')->nullable()->after('payment_id')
                    ->constrained()->restrictOnDelete();
            });
        }

        $accounts = [
            'LOAN-PAYABLE' => ['name' => 'Loans Payable', 'type' => 'liability'],
            'LOAN-RECEIVABLE' => ['name' => 'Loans Receivable', 'type' => 'asset'],
            'LOAN-INTEREST-EXPENSE' => ['name' => 'Loan Interest Expense', 'type' => 'expense'],
            'LOAN-INTEREST-INCOME' => ['name' => 'Loan Interest Income', 'type' => 'revenue'],
        ];
        foreach ($accounts as $code => $account) {
            if (! DB::table('chart_accounts')->where('code', $code)->exists()) {
                DB::table('chart_accounts')->insert([
                    'code' => $code,
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $accountIds = DB::table('chart_accounts')
            ->whereIn('code', array_keys($accounts))
            ->pluck('id', 'code');

        DB::table('loan_payments')
            ->join('loans', 'loans.id', '=', 'loan_payments.loan_id')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'loan_payments.financial_account_id')
            ->leftJoin('journal_entries', 'journal_entries.loan_payment_id', '=', 'loan_payments.id')
            ->whereNull('journal_entries.id')
            ->orderBy('loan_payments.id')
            ->select([
                'loan_payments.id', 'loan_payments.amount', 'loan_payments.payment_date',
                'loan_payments.reference', 'loans.number as loan_number', 'loans.direction',
                'loans.counterparty_id', 'financial_accounts.id as account_id',
                'financial_accounts.owner_branch_id', 'financial_accounts.chart_account_id',
                'financial_accounts.kind as account_kind', 'financial_accounts.current_balance',
                'financial_accounts.credit_limit',
            ])
            ->get()
            ->each(function ($payment) use ($accountIds) {
                $allocation = DB::table('loan_payment_allocations')
                    ->where('loan_payment_id', $payment->id)
                    ->selectRaw('COALESCE(SUM(principal_amount), 0) AS principal')
                    ->selectRaw('COALESCE(SUM(interest_amount + overdue_interest_amount), 0) AS interest')
                    ->first();
                $amount = bcadd((string) $payment->amount, '0', 4);
                $principal = bcadd((string) $allocation->principal, '0', 4);
                $interest = bcadd((string) $allocation->interest, '0', 4);
                $difference = bcsub($amount, bcadd($principal, $interest, 4), 4);
                if (bccomp($difference, '0.0000', 4) !== 0) {
                    $principal = bcadd($principal, $difference, 4);
                }

                $isInflow = $payment->direction === 'lent';
                $entryId = DB::table('journal_entries')->insertGetId([
                    'loan_payment_id' => $payment->id,
                    'kind' => 'payment',
                    'number' => "JE-LOANPAY-{$payment->id}",
                    'entry_date' => $payment->payment_date,
                    'memo' => "Loan payment {$payment->loan_number}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $lines = [[
                    'journal_entry_id' => $entryId,
                    'branch_id' => $payment->owner_branch_id,
                    'chart_account_id' => $payment->chart_account_id,
                    'counterparty_id' => $payment->counterparty_id,
                    'debit' => $isInflow ? $amount : '0.0000',
                    'credit' => $isInflow ? '0.0000' : $amount,
                    'description' => $payment->reference
                        ? "Loan payment: {$payment->reference}"
                        : "Loan payment {$payment->loan_number}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]];
                if (bccomp($principal, '0.0000', 4) === 1) {
                    $lines[] = [
                        'journal_entry_id' => $entryId,
                        'branch_id' => $payment->owner_branch_id,
                        'chart_account_id' => $accountIds[$isInflow ? 'LOAN-RECEIVABLE' : 'LOAN-PAYABLE'],
                        'counterparty_id' => $payment->counterparty_id,
                        'debit' => $isInflow ? '0.0000' : $principal,
                        'credit' => $isInflow ? $principal : '0.0000',
                        'description' => 'Loan principal',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (bccomp($interest, '0.0000', 4) === 1) {
                    $lines[] = [
                        'journal_entry_id' => $entryId,
                        'branch_id' => $payment->owner_branch_id,
                        'chart_account_id' => $accountIds[$isInflow ? 'LOAN-INTEREST-INCOME' : 'LOAN-INTEREST-EXPENSE'],
                        'counterparty_id' => $payment->counterparty_id,
                        'debit' => $isInflow ? '0.0000' : $interest,
                        'credit' => $isInflow ? $interest : '0.0000',
                        'description' => $isInflow ? 'Loan interest income' : 'Loan interest expense',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('journal_lines')->insert($lines);

                $balanceAccount = DB::table('financial_accounts')
                    ->where('id', $payment->account_id)
                    ->lockForUpdate()
                    ->first();
                $effect = $balanceAccount->kind === 'credit_card'
                    ? ($isInflow ? bcsub('0', $amount, 4) : $amount)
                    : ($isInflow ? $amount : bcsub('0', $amount, 4));
                $current = bcadd((string) $balanceAccount->current_balance, $effect, 4);
                $available = $balanceAccount->kind === 'credit_card'
                    ? bcsub((string) ($balanceAccount->credit_limit ?? 0), $current, 4)
                    : $current;
                DB::table('financial_accounts')->where('id', $payment->account_id)->update([
                    'current_balance' => $current,
                    'available_balance' => $available,
                    'balance_updated_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('journal_entries', 'loan_payment_id')) {
            return;
        }

        $entries = DB::table('journal_entries')
            ->join('loan_payments', 'loan_payments.id', '=', 'journal_entries.loan_payment_id')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'loan_payments.financial_account_id')
            ->whereNotNull('journal_entries.loan_payment_id')
            ->select([
                'journal_entries.id', 'financial_accounts.id as account_id',
                'financial_accounts.chart_account_id', 'financial_accounts.kind',
                'financial_accounts.current_balance', 'financial_accounts.credit_limit',
            ])
            ->get();
        $entries->each(function ($entry) {
            $line = DB::table('journal_lines')
                ->where('journal_entry_id', $entry->id)
                ->where('chart_account_id', $entry->chart_account_id)
                ->selectRaw('COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')
                ->first();
            $effect = $entry->kind === 'credit_card'
                ? bcsub((string) $line->credit, (string) $line->debit, 4)
                : bcsub((string) $line->debit, (string) $line->credit, 4);
            $account = DB::table('financial_accounts')->where('id', $entry->account_id)->lockForUpdate()->first();
            $current = bcsub((string) $account->current_balance, $effect, 4);
            DB::table('financial_accounts')->where('id', $entry->account_id)->update([
                'current_balance' => $current,
                'available_balance' => $entry->kind === 'credit_card'
                    ? bcsub((string) ($entry->credit_limit ?? 0), $current, 4)
                    : $current,
                'balance_updated_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $entryIds = $entries->pluck('id');
        DB::table('journal_lines')->whereIn('journal_entry_id', $entryIds)->delete();
        DB::table('journal_entries')->whereIn('id', $entryIds)->delete();

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_payment_id');
        });
    }
};
