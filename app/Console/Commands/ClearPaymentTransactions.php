<?php

namespace App\Console\Commands;

use App\Services\FinancialAccountBalanceService;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ClearPaymentTransactions extends Command
{
    protected $signature = 'accounting:clear-payment-transactions
                            {--source=bakingstore-branch-1 : Integration source whose payments will be removed}
                            {--confirm : Perform the deletion; without this option the command is a dry run}';

    protected $description = 'Remove one integration source\'s payment transactions while preserving accounting master data';

    public function handle(): int
    {
        $source = Str::limit(trim((string) $this->option('source')), 50, '');
        if ($source === '') {
            $this->error('The source name cannot be empty.');

            return self::FAILURE;
        }

        $counts = $this->counts($source);
        $this->table(['Record type', 'Rows'], collect($counts)->map(
            fn (int $count, string $label) => [$label, number_format($count)]
        )->values()->all());

        $this->comment('Preserved: banks/institutions, financial accounts, payment methods, chart accounts, branches, counterparties, and users.');

        if ($counts['Settled payment lines'] > 0) {
            $this->error('Cleanup is blocked because one or more selected payment lines belong to an end-of-day settlement. Void or remove those settlements first.');

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->info('Dry run only. No records were deleted. Add --confirm to perform this exact cleanup.');

            return self::SUCCESS;
        }

        if ($counts['Payments'] === 0) {
            $this->info("No payment transactions were found for source [{$source}].");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($source) {
            $paymentIds = $this->paymentQuery($source)->select('payments.id');
            $lineIds = DB::table('payment_lines')->whereIn('payment_id', clone $paymentIds)->select('id');
            $refundIds = $this->refundQuery($source)->select('customer_refunds.id');

            $obligationIds = collect();
            if (Schema::hasTable('obligation_charges')) {
                $obligationIds = $obligationIds->merge(
                    DB::table('obligation_charges')->whereIn('payment_line_id', clone $lineIds)
                        ->pluck('payment_obligation_id')
                );
            }
            if (Schema::hasTable('payment_allocations')) {
                $obligationIds = $obligationIds->merge(
                    DB::table('payment_allocations')->whereIn('payment_id', clone $paymentIds)
                        ->pluck('payment_obligation_id')
                );
            }
            $obligationIds = $obligationIds->unique()->values();

            DB::table('journal_entries')->where(function (Builder $query) use ($paymentIds, $refundIds) {
                $query->whereIn('payment_id', clone $paymentIds);
                if (Schema::hasColumn('journal_entries', 'customer_refund_id')) {
                    $query->orWhereIn('customer_refund_id', clone $refundIds);
                }
            })->delete();

            if (Schema::hasTable('customer_refunds')) {
                $this->refundQuery($source)->delete();
            }
            if (Schema::hasTable('obligation_charges')) {
                DB::table('obligation_charges')->whereIn('payment_line_id', clone $lineIds)->delete();
            }
            if (Schema::hasTable('payment_allocations')) {
                DB::table('payment_allocations')->whereIn('payment_id', clone $paymentIds)->delete();
            }

            $this->paymentQuery($source)->delete();
            $this->resetDocumentStatuses($source);
            $this->removeEmptyGeneratedObligations($obligationIds);
            app(FinancialAccountBalanceService::class)->rebuildAll();
        });

        $this->info("Payment transactions for source [{$source}] were removed.");
        $this->comment('Accounting master data was preserved. Run the command again without --confirm to verify that zero payments remain.');

        return self::SUCCESS;
    }

    private function paymentQuery(string $source): Builder
    {
        return DB::table('payments')->where(function (Builder $query) use ($source) {
            $query->whereIn('sales_invoice_id', DB::table('sales_invoices')
                ->where('legacy_source', $source.'.shop_order_transaction')->select('id'))
                ->orWhereIn('purchase_order_id', DB::table('purchase_orders')
                    ->where('legacy_source', $source.'.order_supplier_transaction')->select('id'))
                ->orWhereIn('expense_id', DB::table('expenses')
                    ->where('legacy_source', $source.'.expenses_transaction')->select('id'));
        });
    }

    private function refundQuery(string $source): Builder
    {
        return DB::table('customer_refunds')->where(function (Builder $query) use ($source) {
            $query->whereIn('original_payment_id', $this->paymentQuery($source)->select('payments.id'))
                ->orWhereIn('sales_invoice_id', DB::table('sales_invoices')
                    ->where('legacy_source', $source.'.shop_order_transaction')->select('id'));
        });
    }

    private function counts(string $source): array
    {
        $paymentIds = $this->paymentQuery($source)->select('payments.id');
        $lineIds = DB::table('payment_lines')->whereIn('payment_id', clone $paymentIds)->select('id');
        $refundIds = Schema::hasTable('customer_refunds')
            ? $this->refundQuery($source)->select('customer_refunds.id')
            : null;

        $journalEntries = DB::table('journal_entries')->where(function (Builder $query) use ($paymentIds, $refundIds) {
            $query->whereIn('payment_id', clone $paymentIds);
            if ($refundIds && Schema::hasColumn('journal_entries', 'customer_refund_id')) {
                $query->orWhereIn('customer_refund_id', clone $refundIds);
            }
        })->count();

        return [
            'Payments' => $this->paymentQuery($source)->count(),
            'Payment lines' => DB::table('payment_lines')->whereIn('payment_id', clone $paymentIds)->count(),
            'Journal entries' => $journalEntries,
            'Customer refunds' => $refundIds ? $this->refundQuery($source)->count() : 0,
            'Obligation charges' => Schema::hasTable('obligation_charges')
                ? DB::table('obligation_charges')->whereIn('payment_line_id', clone $lineIds)->count() : 0,
            'Payment allocations' => Schema::hasTable('payment_allocations')
                ? DB::table('payment_allocations')->whereIn('payment_id', clone $paymentIds)->count() : 0,
            'Settled payment lines' => Schema::hasTable('end_of_day_settlement_items')
                ? DB::table('end_of_day_settlement_items')->whereIn('payment_line_id', clone $lineIds)->count() : 0,
        ];
    }

    private function resetDocumentStatuses(string $source): void
    {
        DB::table('sales_invoices')
            ->where('legacy_source', $source.'.shop_order_transaction')
            ->where('status', '!=', 'cancelled')
            ->update(['status' => 'unpaid', 'updated_at' => now()]);
        DB::table('purchase_orders')
            ->where('legacy_source', $source.'.order_supplier_transaction')
            ->where('status', '!=', 'cancelled')
            ->update(['status' => 'open', 'updated_at' => now()]);
        DB::table('expenses')
            ->where('legacy_source', $source.'.expenses_transaction')
            ->where('status', '!=', 'cancelled')
            ->update(['status' => 'unpaid', 'updated_at' => now()]);
    }

    private function removeEmptyGeneratedObligations($obligationIds): void
    {
        if ($obligationIds->isEmpty() || ! Schema::hasTable('payment_obligations')) {
            return;
        }

        DB::table('payment_obligations')->whereIn('id', $obligationIds)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('obligation_charges')
                ->whereColumn('obligation_charges.payment_obligation_id', 'payment_obligations.id'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('payment_allocations')
                ->whereColumn('payment_allocations.payment_obligation_id', 'payment_obligations.id'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('financial_account_adjustments')
                ->whereColumn('financial_account_adjustments.payment_obligation_id', 'payment_obligations.id'))
            ->delete();
    }
}
