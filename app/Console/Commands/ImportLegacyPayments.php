<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\LegacyEntityMapping;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\PaymentMethod;
use App\Services\IntegratedCustomerOrderService;
use App\Services\IntegratedPurchaseOrderService;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

class ImportLegacyPayments extends Command
{
    protected $signature = 'accounting:import-legacy-payments
                            {--source=bakingstore-branch-1 : Stable source-system key for this branch database}
                            {--account-source=baker : Existing legacy source used by imported financial accounts}
                            {--type=all : all, customer, or purchase-order}
                            {--branch-legacy-id= : Shop ID to use for purchase orders; auto-detected when omitted}
                            {--chunk=500 : Number of legacy rows read per chunk}
                            {--from-id=0 : Only consider legacy payment IDs greater than or equal to this value}
                            {--limit=0 : Maximum rows per payment type; zero imports all}
                            {--dry-run : Audit the migration without writing to Accounting}';

    protected $description = 'Import branch-scoped legacy customer and purchase-order payments into Central Accounting';

    private array $stats = [];

    private array $failures = [];

    private array $accountCache = [];

    private array $methodCache = [];

    public function __construct(
        private IntegratedCustomerOrderService $customerOrders,
        private IntegratedPurchaseOrderService $purchaseOrders,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->stats = [];
        $this->failures = [];
        $this->accountCache = [];
        $this->methodCache = [];

        $source = trim((string) $this->option('source'));
        $accountSource = trim((string) $this->option('account-source'));
        $type = (string) $this->option('type');
        $chunk = (int) $this->option('chunk');
        $fromId = max(0, (int) $this->option('from-id'));
        $limit = max(0, (int) $this->option('limit'));

        if ($source === '' || strlen($source) > 50) {
            $this->error('The source must contain 1 to 50 characters.');

            return self::FAILURE;
        }
        if ($accountSource === '' || strlen($accountSource) > 50) {
            $this->error('The account source must contain 1 to 50 characters.');

            return self::FAILURE;
        }
        if (! in_array($type, ['all', 'customer', 'purchase-order'], true)) {
            $this->error('The type must be all, customer, or purchase-order.');

            return self::FAILURE;
        }
        if ($chunk < 1 || $chunk > 5000) {
            $this->error('The chunk size must be between 1 and 5000.');

            return self::FAILURE;
        }

        try {
            $legacy = DB::connection('legacy_mysql');
            $legacy->getPdo();
        } catch (Throwable $exception) {
            $this->error('Unable to read the legacy database: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Legacy payment migration');
        $this->line('Source system: '.$source);
        $this->line('Legacy database: '.($legacy->getDatabaseName() ?: 'legacy_mysql'));
        $this->line('Account mapping source: '.$accountSource.'.payment_type_po / '.$accountSource.'.payment_type');
        $this->line('Mode: '.($this->option('dry-run') ? 'DRY RUN (no writes)' : 'IMPORT'));

        if ($this->option('dry-run')) {
            return $this->dryRun($legacy, $source, $accountSource, $type, $fromId, $limit);
        }

        if (! Schema::hasTable('legacy_entity_mappings')) {
            $this->error('Run php artisan migrate before importing payments.');

            return self::FAILURE;
        }

        $branch = $this->resolvePurchaseOrderBranch($legacy, $source);
        if (in_array($type, ['all', 'customer'], true)) {
            $this->importCustomerPayments($legacy, $source, $accountSource, $chunk, $fromId, $limit);
        }
        if (in_array($type, ['all', 'purchase-order'], true)) {
            if (! $branch) {
                $this->error('Could not resolve the purchase-order branch. Pass --branch-legacy-id=<shop id>.');

                return self::FAILURE;
            }
            $this->importPurchaseOrderPayments($legacy, $source, $accountSource, $branch, $chunk, $fromId, $limit);
        }

        $this->renderImportSummary();

        return empty($this->failures) ? self::SUCCESS : self::INVALID;
    }

    private function dryRun(ConnectionInterface $legacy, string $source, string $accountSource, string $type, int $fromId, int $limit): int
    {
        $rows = [];
        if (in_array($type, ['all', 'customer'], true)) {
            $rows[] = $this->auditType(
                $legacy,
                $source,
                $accountSource,
                'Customer order',
                'mode_of_payment',
                'payment_type_id',
                'shop_order_transaction',
                'shop_order_transaction_id',
                $fromId,
                $limit,
            );
        }
        if (in_array($type, ['all', 'purchase-order'], true)) {
            $rows[] = $this->auditType(
                $legacy,
                $source,
                $accountSource,
                'Purchase order',
                'mode_of_payment_po',
                'payment_type_po_id',
                'order_supplier_transaction',
                'order_supplier_transaction_id',
                $fromId,
                $limit,
            );
        }

        $this->newLine();
        $this->table(
            ['Payment type', 'Selected', 'Amount', 'Already imported', 'Orphan parent', 'Invalid amount', 'Missing account'],
            $rows
        );
        $this->newLine();
        $this->comment('Dry run complete. Nothing was written to the Accounting database.');
        $this->comment('Orphan, invalid, and missing-account rows will be rejected during the real import.');

        return self::SUCCESS;
    }

    private function auditType(
        ConnectionInterface $legacy,
        string $source,
        string $accountSource,
        string $label,
        string $paymentTable,
        string $accountColumn,
        string $parentTable,
        string $parentColumn,
        int $fromId,
        int $limit,
    ): array {
        $query = $legacy->table($paymentTable.' as payment')->where('payment.id', '>=', $fromId);
        $maxSelectedId = null;
        if ($limit > 0) {
            $maxSelectedId = (clone $query)->orderBy('payment.id')->skip($limit - 1)->value('payment.id');
            if ($maxSelectedId !== null) {
                $query->where('payment.id', '<=', $maxSelectedId);
            }
        }

        $selected = (clone $query)->count();
        $amount = (clone $query)->sum('payment.amount');
        $orphan = (clone $query)
            ->leftJoin($parentTable.' as parent', 'parent.id', '=', 'payment.'.$parentColumn)
            ->whereNull('parent.id')->count();
        $invalid = (clone $query)->where('payment.amount', '<=', 0)->count();
        $accountIds = (clone $query)->distinct()->pluck('payment.'.$accountColumn)->map(fn ($id) => (int) $id);
        $accountTables = $paymentTable === 'mode_of_payment'
            ? ['payment_type_po', 'payment_type']
            : ['payment_type_po'];
        $missingAccounts = $accountIds->filter(
            fn (int $id) => ! $this->resolveAccount($source, $accountSource, $id, false, $accountTables)
        )->count();
        $imported = PaymentLine::where('legacy_source', $source.'.'.$paymentTable)
            ->where('legacy_id', '>=', $fromId)
            ->when($maxSelectedId !== null, fn ($central) => $central->where('legacy_id', '<=', $maxSelectedId))
            ->count();

        return [
            $label,
            number_format($selected),
            'PHP '.number_format((float) $amount, 2),
            number_format($imported),
            number_format($orphan),
            number_format($invalid),
            number_format($missingAccounts).' account IDs',
        ];
    }

    private function importCustomerPayments(ConnectionInterface $legacy, string $source, string $accountSource, int $chunk, int $fromId, int $limit): void
    {
        $processed = 0;
        $query = $legacy->table('mode_of_payment as payment')
            ->leftJoin('shop_order_transaction as orders', 'orders.id', '=', 'payment.shop_order_transaction_id')
            ->leftJoin('shop', 'shop.id', '=', 'orders.shop_id')
            ->leftJoin('customer', 'customer.id', '=', 'orders.requestor')
            ->where('payment.id', '>=', $fromId)
            ->select([
                'payment.id', 'payment.payment_type_id', 'payment.shop_order_transaction_id',
                'payment.amount', 'payment.is_paid', 'payment.created_at as payment_date',
                'payment.updated_at as payment_updated_at',
                'orders.id as parent_id', 'orders.shop_id', 'orders.requestor',
                'orders.shop_order_transaction_total_price as order_total',
                'orders.date as order_date', 'orders.created_at as order_created_at',
                'shop.shop_name', 'customer.first_name', 'customer.last_name', 'customer.store_name',
            ])
            ->orderBy('payment.id');

        $query->chunkById($chunk, function ($rows) use ($source, $accountSource, $limit, &$processed) {
            foreach ($rows as $row) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }
                $processed++;
                $this->importCustomerRow($row, $source, $accountSource);
                if ($processed % 1000 === 0) {
                    $this->line('Customer payments processed: '.number_format($processed));
                }
            }

            return true;
        }, 'payment.id', 'id');
    }

    private function importCustomerRow(object $row, string $source, string $accountSource): void
    {
        $legacyTable = 'mode_of_payment';
        if ($this->alreadyImported($source, $legacyTable, (int) $row->id)) {
            $this->increment($legacyTable, 'skipped');

            return;
        }
        if (! $row->parent_id) {
            $this->reject($legacyTable, (int) $row->id, 'Missing shop_order_transaction '.$row->shop_order_transaction_id);

            return;
        }
        if ((float) $row->amount <= 0) {
            $this->reject($legacyTable, (int) $row->id, 'Amount must be greater than zero');

            return;
        }
        $account = $this->resolveAccount(
            $source,
            $accountSource,
            (int) $row->payment_type_id,
            true,
            ['payment_type_po', 'payment_type'],
        );
        if (! $account) {
            $this->reject($legacyTable, (int) $row->id, 'Missing account mapping for payment_type_po '.$row->payment_type_id);

            return;
        }

        $key = $this->idempotencyKey($source, $legacyTable, (int) $row->id);
        try {
            $this->withHistoricalAccountEnabled($account, function () use ($row, $source, $key, $account) {
                $this->customerOrders->recordPayment([
                'source' => $source,
                'external_branch_id' => (int) $row->shop_id,
                'branch_name' => trim((string) $row->shop_name) ?: 'Legacy branch '.$row->shop_id,
                'external_customer_id' => (int) $row->requestor,
                'customer_name' => $this->customerName($row),
                'external_order_id' => (int) $row->shop_order_transaction_id,
                'order_date' => $row->order_date ?: $row->order_created_at ?: $row->payment_date,
                'order_total' => $row->order_total,
                'financial_account_id' => $account->id,
                'payment_method_id' => $this->paymentMethod($account, false)->id,
                'payment_date' => $row->payment_date ?: $row->order_date ?: $row->order_created_at,
                'amount' => $row->amount,
                'currency' => 'PHP',
                'reference' => 'Legacy customer payment #'.$row->id,
                'idempotency_key' => $key,
                ], null);
            });
            $this->attachLegacyIdentity($source, $legacyTable, (int) $row->id, $key, (bool) $row->is_paid, $row->payment_updated_at);
            $this->increment($legacyTable, 'imported', (float) $row->amount);
        } catch (Throwable $exception) {
            $this->reject($legacyTable, (int) $row->id, $this->failureMessage($exception));
        }
    }

    private function importPurchaseOrderPayments(ConnectionInterface $legacy, string $source, string $accountSource, array $branch, int $chunk, int $fromId, int $limit): void
    {
        $processed = 0;
        $query = $legacy->table('mode_of_payment_po as payment')
            ->leftJoin('order_supplier_transaction as orders', 'orders.id', '=', 'payment.order_supplier_transaction_id')
            ->leftJoin('supplier', 'supplier.id', '=', 'orders.supplier_id')
            ->leftJoin('installment_payment_transaction as installment', 'installment.mode_of_payment_po_id', '=', 'payment.id')
            ->where('payment.id', '>=', $fromId)
            ->select([
                'payment.id', 'payment.payment_type_po_id', 'payment.payment_term_id',
                'payment.order_supplier_transaction_id', 'payment.amount', 'payment.date as payment_date',
                'orders.id as parent_id', 'orders.supplier_id', 'orders.total_transaction_price as order_total',
                'orders.order_date', 'orders.created_at as order_created_at', 'orders.invoice_number',
                'supplier.supplier_name', 'installment.number_of_months',
            ])
            ->orderBy('payment.id');

        $query->chunkById($chunk, function ($rows) use ($source, $accountSource, $branch, $limit, &$processed) {
            foreach ($rows as $row) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }
                $processed++;
                $this->importPurchaseOrderRow($row, $source, $accountSource, $branch);
                if ($processed % 1000 === 0) {
                    $this->line('Purchase-order payments processed: '.number_format($processed));
                }
            }

            return true;
        }, 'payment.id', 'id');
    }

    private function importPurchaseOrderRow(object $row, string $source, string $accountSource, array $branch): void
    {
        $legacyTable = 'mode_of_payment_po';
        if ($this->alreadyImported($source, $legacyTable, (int) $row->id)) {
            $this->increment($legacyTable, 'skipped');

            return;
        }
        if (! $row->parent_id) {
            $this->reject($legacyTable, (int) $row->id, 'Missing order_supplier_transaction '.$row->order_supplier_transaction_id);

            return;
        }
        if ((float) $row->amount <= 0) {
            $this->reject($legacyTable, (int) $row->id, 'Amount must be greater than zero');

            return;
        }
        $account = $this->resolveAccount($source, $accountSource, (int) $row->payment_type_po_id);
        if (! $account) {
            $this->reject($legacyTable, (int) $row->id, 'Missing account mapping for payment_type_po '.$row->payment_type_po_id);

            return;
        }

        $key = $this->idempotencyKey($source, $legacyTable, (int) $row->id);
        try {
            $this->withHistoricalAccountEnabled($account, function () use ($row, $source, $key, $account, $branch) {
                $this->purchaseOrders->recordPayment([
                    'source' => $source,
                    'external_branch_id' => $branch['id'],
                    'branch_name' => $branch['name'],
                    'external_supplier_id' => (int) $row->supplier_id,
                    'supplier_name' => trim((string) $row->supplier_name) ?: 'Legacy supplier '.$row->supplier_id,
                    'external_order_id' => (int) $row->order_supplier_transaction_id,
                    'order_date' => $row->order_date ?: $row->order_created_at ?: $row->payment_date,
                    'order_total' => $row->order_total,
                    'financial_account_id' => $account->id,
                    'payment_method_id' => $this->paymentMethod($account, true, (int) $row->payment_term_id)->id,
                    'payment_date' => $row->payment_date ?: $row->order_date ?: $row->order_created_at,
                    'amount' => $row->amount,
                    'currency' => 'PHP',
                    'installment_count' => max(1, (int) ($row->number_of_months ?? 1)),
                    'reference' => trim((string) $row->invoice_number) ?: 'Legacy PO payment #'.$row->id,
                    'idempotency_key' => $key,
                ], null);
            });
            $this->attachLegacyIdentity($source, $legacyTable, (int) $row->id, $key);
            $this->increment($legacyTable, 'imported', (float) $row->amount);
        } catch (Throwable $exception) {
            $this->reject($legacyTable, (int) $row->id, $this->failureMessage($exception));
        }
    }

    private function resolveAccount(
        string $source,
        string $accountSource,
        int $legacyId,
        bool $writeMapping = true,
        array $legacyTables = ['payment_type_po'],
    ): ?FinancialAccount
    {
        $cacheKey = $source.':'.$accountSource.':'.implode(',', $legacyTables).':'.$legacyId;
        if (array_key_exists($cacheKey, $this->accountCache)) {
            [$account, $resolvedTable] = $this->accountCache[$cacheKey];
            if ($account && $resolvedTable && $writeMapping) {
                $this->storeAccountMapping($source, $resolvedTable, $legacyId, $account);
            }

            return $account;
        }

        $account = null;
        $resolvedTable = null;
        foreach ($legacyTables as $legacyTable) {
            $mapping = Schema::hasTable('legacy_entity_mappings')
                ? LegacyEntityMapping::where('legacy_table', $legacyTable)
                    ->where('legacy_id', $legacyId)
                    ->where('central_type', FinancialAccount::class)
                    ->whereIn('source_system', [$source, $accountSource])
                    ->orderByRaw('CASE WHEN source_system = ? THEN 0 ELSE 1 END', [$source])
                    ->first()
                : null;
            $account = $mapping ? FinancialAccount::find($mapping->central_id) : null;
            $account ??= FinancialAccount::where([
                'legacy_source' => $source.'.'.$legacyTable,
                'legacy_id' => $legacyId,
            ])->first();
            $account ??= FinancialAccount::where([
                'legacy_source' => $accountSource.'.'.$legacyTable,
                'legacy_id' => $legacyId,
            ])->first();
            if ($account) {
                $resolvedTable = $legacyTable;
                break;
            }
        }

        if ($account && $writeMapping && Schema::hasTable('legacy_entity_mappings')) {
            $this->storeAccountMapping($source, $resolvedTable, $legacyId, $account);
        }

        $this->accountCache[$cacheKey] = [$account, $resolvedTable];

        return $account;
    }

    private function storeAccountMapping(string $source, string $legacyTable, int $legacyId, FinancialAccount $account): void
    {
        if (! Schema::hasTable('legacy_entity_mappings')) {
            return;
        }
        LegacyEntityMapping::updateOrCreate(
            ['source_system' => $source, 'legacy_table' => $legacyTable, 'legacy_id' => $legacyId],
            ['central_type' => FinancialAccount::class, 'central_id' => $account->id]
        );
    }

    private function paymentMethod(FinancialAccount $account, bool $isPurchaseOrder, ?int $legacyTermId = null): PaymentMethod
    {
        $code = match ($account->kind) {
            'cash' => 'CASH',
            'e_wallet' => 'E_WALLET',
            'credit_card' => 'CREDIT_CARD',
            'checking_account' => $isPurchaseOrder && $legacyTermId === 3 ? 'CHEQUE' : 'BANK_TRANSFER',
            default => 'BANK_TRANSFER',
        };

        return $this->methodCache[$code] ??= PaymentMethod::where('code', $code)->firstOrFail();
    }

    private function withHistoricalAccountEnabled(FinancialAccount $account, callable $callback): mixed
    {
        if ($account->is_active) {
            return $callback();
        }

        $historical = LegacyEntityMapping::where('central_type', FinancialAccount::class)
            ->where('central_id', $account->id)
            ->whereIn('legacy_table', ['payment_type', 'payment_type_po'])
            ->get()
            ->isNotEmpty();
        if (! $historical) {
            return $callback();
        }

        return DB::transaction(function () use ($account, $callback) {
            $account->update(['is_active' => true]);
            try {
                return $callback();
            } finally {
                $account->update(['is_active' => false]);
            }
        });
    }

    private function alreadyImported(string $source, string $table, int $legacyId): bool
    {
        return PaymentLine::where(['legacy_source' => $source.'.'.$table, 'legacy_id' => $legacyId])->exists();
    }

    private function attachLegacyIdentity(string $source, string $table, int $legacyId, string $key, bool $confirmed = false, mixed $confirmedAt = null): void
    {
        $payment = Payment::where('idempotency_key', $key)->firstOrFail();
        $line = $payment->lines()->firstOrFail();
        $line->update([
            'legacy_source' => $source.'.'.$table,
            'legacy_id' => $legacyId,
            'confirmed_at' => $confirmed ? ($confirmedAt ?: now()) : null,
        ]);
        LegacyEntityMapping::updateOrCreate(
            ['source_system' => $source, 'legacy_table' => $table, 'legacy_id' => $legacyId],
            ['central_type' => PaymentLine::class, 'central_id' => $line->id]
        );
    }

    private function resolvePurchaseOrderBranch(ConnectionInterface $legacy, string $source): ?array
    {
        $legacyId = $this->option('branch-legacy-id');
        if ($legacyId !== null && $legacyId !== '') {
            $shop = $legacy->table('shop')->where('id', (int) $legacyId)->first();

            return $shop ? ['id' => (int) $shop->id, 'name' => (string) $shop->shop_name] : null;
        }

        $shop = $legacy->table('mode_of_payment as payment')
            ->join('shop_order_transaction as orders', 'orders.id', '=', 'payment.shop_order_transaction_id')
            ->join('shop', 'shop.id', '=', 'orders.shop_id')
            ->selectRaw('shop.id, shop.shop_name, COUNT(*) as payment_count')
            ->groupBy('shop.id', 'shop.shop_name')
            ->orderByDesc('payment_count')
            ->first();
        if ($shop) {
            return ['id' => (int) $shop->id, 'name' => (string) $shop->shop_name];
        }

        $branch = Branch::where('legacy_source', $source.'.shop')->first();

        return $branch ? ['id' => (int) $branch->legacy_id, 'name' => $branch->name] : null;
    }

    private function customerName(object $row): string
    {
        if ((int) $row->requestor === 0) {
            return 'Walk-in Customer';
        }
        $name = trim(implode(' ', array_filter([(string) $row->first_name, (string) $row->last_name])));

        return trim((string) $row->store_name) ?: ($name ?: 'Legacy customer '.$row->requestor);
    }

    private function idempotencyKey(string $source, string $table, int $legacyId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $source.':'.$table.':'.$legacyId)->toString();
    }

    private function increment(string $table, string $key, float $amount = 0): void
    {
        $this->stats[$table][$key] = ($this->stats[$table][$key] ?? 0) + 1;
        if ($amount !== 0.0) {
            $this->stats[$table]['amount'] = ($this->stats[$table]['amount'] ?? 0) + $amount;
        }
    }

    private function reject(string $table, int $id, string $reason): void
    {
        $this->increment($table, 'rejected');
        if (count($this->failures) < 50) {
            $this->failures[] = [$table, $id, Str::limit($reason, 180)];
        }
    }

    private function failureMessage(Throwable $exception): string
    {
        if (method_exists($exception, 'errors')) {
            return collect($exception->errors())->flatten()->first() ?? $exception->getMessage();
        }

        return $exception->getMessage();
    }

    private function renderImportSummary(): void
    {
        $rows = [];
        foreach (['mode_of_payment', 'mode_of_payment_po'] as $table) {
            $stats = $this->stats[$table] ?? [];
            $rows[] = [
                $table,
                number_format($stats['imported'] ?? 0),
                'PHP '.number_format($stats['amount'] ?? 0, 2),
                number_format($stats['skipped'] ?? 0),
                number_format($stats['rejected'] ?? 0),
            ];
        }
        $this->newLine();
        $this->table(['Legacy table', 'Imported', 'Amount', 'Already imported', 'Rejected'], $rows);
        if ($this->failures !== []) {
            $this->warn('First '.count($this->failures).' rejected rows:');
            $this->table(['Legacy table', 'Legacy ID', 'Reason'], $this->failures);
        }
        $this->comment('The command is resumable. Run it again after correcting rejected rows; imported identities will be skipped.');
    }
}
