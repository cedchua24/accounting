<?php

namespace App\Console\Commands;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\FinancialAccountApplicationType;
use App\Models\FinancialInstitution;
use App\Models\LegacyEntityMapping;
use App\Models\UserAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ImportLegacyPaymentAccounts extends Command
{
    protected $signature = 'accounting:import-legacy-payment-accounts
                            {--source=baker : Stable name of the source system}
                            {--dry-run : Validate and display the import without writing}';

    protected $description = 'Import legacy bank and payment_type_po master data using fresh central IDs';

    public function handle(): int
    {
        $source = Str::limit(trim((string) $this->option('source')), 30, '');
        if ($source === '') {
            $this->error('The source name cannot be empty.');

            return self::FAILURE;
        }

        try {
            $legacy = DB::connection('legacy_mysql');
            $banks = $legacy->table('bank')->orderBy('id')->get();
            $accounts = $legacy
                ->table('payment_type_po as account')
                ->join('bank', 'bank.id', '=', 'account.bank_id')
                ->join('payment_term', 'payment_term.id', '=', 'account.payment_term_id')
                ->select('account.*', 'bank.bank_name', 'payment_term.payment_term')
                ->orderBy('account.id')
                ->get();
            $historicalReceiptAccounts = $legacy->table('payment_type')
                ->whereIn('id', $legacy->table('mode_of_payment')->distinct()->pluck('payment_type_id'))
                ->orderBy('id')
                ->get();
            $usage = [
                FinancialAccountApplication::CUSTOMER_RECEIPT => $legacy->table('mode_of_payment as payment')
                    ->join('payment_type_po as account', 'account.id', '=', 'payment.payment_type_id')
                    ->distinct()->pluck('account.id')->map(fn ($id) => (int) $id)->flip(),
                FinancialAccountApplication::PURCHASE_ORDER_PAYMENT => $legacy->table('mode_of_payment_po')
                    ->distinct()->pluck('payment_type_po_id')->map(fn ($id) => (int) $id)->flip(),
                FinancialAccountApplication::EXPENSE_PAYMENT => $legacy->table('expenses_transaction')
                    ->where('payment_type_po_id', '>', 0)->distinct()->pluck('payment_type_po_id')->map(fn ($id) => (int) $id)->flip(),
                FinancialAccountApplication::OBLIGATION_PAYMENT => $legacy->table('credit_card_pay')
                    ->distinct()->pluck('payment_type_po_id')->map(fn ($id) => (int) $id)->flip(),
            ];
        } catch (\Throwable $exception) {
            $this->error('Unable to read the legacy database: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Found {$banks->count()} banks, {$accounts->count()} payment accounts, and {$historicalReceiptAccounts->count()} historical receipt accounts.");
        $this->validateLegacyRows($banks, $accounts);

        if ($this->option('dry-run')) {
            $this->table(
                ['Legacy ID', 'Term', 'Kind', 'Institution', 'Account', 'Applications'],
                $accounts->map(fn ($row) => [
                    $row->id,
                    $row->payment_term,
                    $this->accountKind($row),
                    $row->bank_name,
                    $this->displayName($row),
                    implode(', ', $this->applicationsFor($row, $usage)),
                ])->all()
            );
            $this->table(
                ['Legacy table', 'Legacy ID', 'Historical receipt account', 'Reference'],
                $historicalReceiptAccounts->map(fn ($row) => [
                    'payment_type', $row->id, $row->payment_type, $row->payment_type_description,
                ])->all()
            );
            $this->comment('Dry run complete. Legacy balances were intentionally excluded.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($source, $banks, $accounts, $historicalReceiptAccounts, $usage) {
            foreach ($banks as $bank) {
                $this->importInstitution($source, $bank);
            }

            foreach ($accounts as $account) {
                $this->importAccount($source, $account, $usage);
            }

            foreach ($historicalReceiptAccounts as $account) {
                $this->importHistoricalReceiptAccount($source, $account);
            }
        });

        $this->info("Imported {$banks->count()} financial institutions, {$accounts->count()} current accounts, and mapped {$historicalReceiptAccounts->count()} historical receipt accounts.");
        $this->comment('Fresh central IDs were generated. Cached balance and total_balance_due values were not imported.');

        return self::SUCCESS;
    }

    private function validateLegacyRows($banks, $accounts): void
    {
        if ($banks->isEmpty() || $accounts->isEmpty()) {
            throw new RuntimeException('The legacy bank or payment_type_po table is empty.');
        }

        foreach ($accounts as $account) {
            if ((int) $account->payment_term_id === 4
                && (! in_array((int) $account->statement_date, range(1, 31), true)
                    || ! in_array((int) $account->due_date, range(1, 31), true))) {
                throw new RuntimeException("Credit card {$account->id} has an invalid statement or due day.");
            }
        }
    }

    private function importInstitution(string $source, object $bank): void
    {
        $code = $this->institutionCode($bank->bank_name);
        $institution = FinancialInstitution::where([
            'legacy_source' => $source.'.bank',
            'legacy_id' => (int) $bank->id,
        ])->first() ?? FinancialInstitution::where('code', $code)->first() ?? new FinancialInstitution;
        $institution->fill([
            'code' => $code,
            'name' => trim($bank->bank_name),
            'type' => $this->institutionType((int) $bank->id),
            'is_active' => (int) $bank->status === 0,
            'legacy_source' => $source.'.bank',
            'legacy_id' => (int) $bank->id,
        ])->save();

        LegacyEntityMapping::updateOrCreate(
            ['source_system' => $source, 'legacy_table' => 'bank', 'legacy_id' => (int) $bank->id],
            ['central_type' => FinancialInstitution::class, 'central_id' => $institution->id]
        );
    }

    private function importAccount(string $source, object $row, array $usage): void
    {
        $mappedAccountId = LegacyEntityMapping::where([
            'source_system' => $source,
            'legacy_table' => 'payment_type_po',
            'legacy_id' => (int) $row->id,
            'central_type' => FinancialAccount::class,
        ])->value('central_id');
        $account = $mappedAccountId ? FinancialAccount::find($mappedAccountId) : null;
        $account ??= FinancialAccount::where([
            'legacy_source' => $source.'.payment_type_po',
            'legacy_id' => (int) $row->id,
        ])->first();

        $institutionId = LegacyEntityMapping::where([
            'source_system' => $source,
            'legacy_table' => 'bank',
            'legacy_id' => (int) $row->bank_id,
            'central_type' => FinancialInstitution::class,
        ])->value('central_id');
        if (! $institutionId) {
            throw new RuntimeException("Bank {$row->bank_id} was not mapped before account {$row->id}.");
        }

        $kind = $this->accountKind($row);
        $applications = $this->applicationsFor($row, $usage);
        $ledger = ChartAccount::updateOrCreate(
            ['code' => 'LEGACY-FA-'.$row->id],
            [
                'name' => $this->ledgerName($row),
                'type' => $kind === 'credit_card' ? 'liability' : 'asset',
                'parent_id' => ChartAccount::where('code', $kind === 'credit_card' ? '2010' : '1000')->value('id'),
                'is_active' => (int) $row->status === 0,
            ]
        );

        $account ??= new FinancialAccount;
        $account->fill([
            'owner_branch_id' => null,
            'is_company_wide' => true,
            'financial_institution_id' => $institutionId,
            'chart_account_id' => $ledger->id,
            'kind' => $kind,
            'name' => $this->displayName($row),
            'account_number' => $kind === 'cash' ? null : trim((string) $row->account_number),
            'account_holder' => $this->resolveAccountHolderId((string) $row->account_name),
            'description' => trim((string) $row->account_description) ?: null,
            'currency' => 'PHP',
            'credit_limit' => $kind === 'credit_card' ? max(0, (float) $row->credit_limit) : null,
            'statement_day' => $kind === 'credit_card' ? (int) $row->statement_date : null,
            'due_day' => $kind === 'credit_card' ? (int) $row->due_date : null,
            'buffer_days' => max(0, (int) $row->buffer_days),
            'usable_for_receipts' => in_array(FinancialAccountApplication::CUSTOMER_RECEIPT, $applications, true),
            'usable_for_payments' => collect($applications)->intersect([
                FinancialAccountApplication::PURCHASE_ORDER_PAYMENT,
                FinancialAccountApplication::EXPENSE_PAYMENT,
                FinancialAccountApplication::OBLIGATION_PAYMENT,
                FinancialAccountApplication::DIRECT_PAYMENT,
            ])->isNotEmpty(),
            'is_active' => (int) $row->status === 0,
            'legacy_source' => $source.'.payment_type_po',
            'legacy_id' => (int) $row->id,
        ])->save();

        LegacyEntityMapping::updateOrCreate(
            ['source_system' => $source, 'legacy_table' => 'payment_type_po', 'legacy_id' => (int) $row->id],
            ['central_type' => FinancialAccount::class, 'central_id' => $account->id]
        );

        $applicationIds = FinancialAccountApplicationType::query()
            ->whereIn('code', $applications)
            ->pluck('id', 'code');
        $account->applications()->where('source', 'legacy_import')->whereNotIn('application_id', $applicationIds->values())->delete();
        foreach ($applications as $application) {
            $account->applications()->firstOrCreate(
                ['application_id' => $applicationIds->get($application)],
                ['source' => 'legacy_import']
            );
        }
    }

    private function applicationsFor(object $row, array $usage): array
    {
        $applications = collect();
        if ($this->accountKind($row) === 'credit_card') {
            $applications->push(
                FinancialAccountApplication::PURCHASE_ORDER_PAYMENT,
                FinancialAccountApplication::EXPENSE_PAYMENT,
            );
        }
        if ((bool) $row->is_customer || $usage[FinancialAccountApplication::CUSTOMER_RECEIPT]->has((int) $row->id)) {
            $applications->push(FinancialAccountApplication::CUSTOMER_RECEIPT);
        }
        if ((bool) $row->is_supplier || $usage[FinancialAccountApplication::PURCHASE_ORDER_PAYMENT]->has((int) $row->id)) {
            $applications->push(FinancialAccountApplication::PURCHASE_ORDER_PAYMENT);
        }
        if ($usage[FinancialAccountApplication::EXPENSE_PAYMENT]->has((int) $row->id)) {
            $applications->push(FinancialAccountApplication::EXPENSE_PAYMENT);
        }
        if ($usage[FinancialAccountApplication::OBLIGATION_PAYMENT]->has((int) $row->id)) {
            $applications->push(FinancialAccountApplication::OBLIGATION_PAYMENT);
        }

        return $applications->isEmpty()
            ? [FinancialAccountApplication::UNCLASSIFIED]
            : $applications->unique()->sort()->values()->all();
    }

    private function importHistoricalReceiptAccount(string $source, object $row): void
    {
        $existingCurrent = LegacyEntityMapping::where([
            'source_system' => $source,
            'legacy_table' => 'payment_type_po',
            'legacy_id' => (int) $row->id,
            'central_type' => FinancialAccount::class,
        ])->first();
        if ($existingCurrent && $account = FinancialAccount::find($existingCurrent->central_id)) {
            LegacyEntityMapping::updateOrCreate(
                ['source_system' => $source, 'legacy_table' => 'payment_type', 'legacy_id' => (int) $row->id],
                ['central_type' => FinancialAccount::class, 'central_id' => $account->id, 'metadata' => ['historical_only' => false]]
            );

            return;
        }

        $mappedId = LegacyEntityMapping::where([
            'source_system' => $source,
            'legacy_table' => 'payment_type',
            'legacy_id' => (int) $row->id,
            'central_type' => FinancialAccount::class,
        ])->value('central_id');
        $account = $mappedId ? FinancialAccount::find($mappedId) : null;
        $kind = $this->historicalReceiptKind((string) $row->payment_type);
        $institution = $this->historicalReceiptInstitution((string) $row->payment_type);
        $ledger = ChartAccount::updateOrCreate(
            ['code' => 'LEGACY-RECEIPT-'.strtoupper(dechex(crc32($source))).'-'.$row->id],
            [
                'name' => Str::limit('Historical receipt - '.trim((string) $row->payment_type), 255, ''),
                'type' => 'asset',
                'parent_id' => ChartAccount::where('code', '1000')->value('id'),
                'is_active' => false,
            ]
        );

        $account ??= new FinancialAccount;
        $account->fill([
            'owner_branch_id' => null,
            'is_company_wide' => true,
            'financial_institution_id' => $institution?->id,
            'chart_account_id' => $ledger->id,
            'kind' => $kind,
            'name' => 'Historical - '.trim((string) $row->payment_type),
            'account_number' => null,
            'account_holder' => null,
            'description' => 'Legacy payment_type reference: '.trim((string) $row->payment_type_description),
            'currency' => 'PHP',
            'usable_for_receipts' => true,
            'usable_for_payments' => false,
            'is_active' => false,
            'legacy_source' => $source.'.payment_type',
            'legacy_id' => (int) $row->id,
        ])->save();
        $account->applications()->firstOrCreate(
            ['application_id' => FinancialAccountApplicationType::where('code', FinancialAccountApplication::CUSTOMER_RECEIPT)->value('id')],
            ['source' => 'legacy_historical']
        );
        LegacyEntityMapping::updateOrCreate(
            ['source_system' => $source, 'legacy_table' => 'payment_type', 'legacy_id' => (int) $row->id],
            [
                'central_type' => FinancialAccount::class,
                'central_id' => $account->id,
                'metadata' => ['historical_only' => true],
            ]
        );
    }

    private function historicalReceiptKind(string $name): string
    {
        $name = Str::upper($name);

        return match (true) {
            str_contains($name, 'CASH') && ! str_contains($name, 'GCASH') => 'cash',
            str_contains($name, 'GCASH'), str_contains($name, 'MAYA') => 'e_wallet',
            default => 'bank_account',
        };
    }

    private function resolveAccountHolderId(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $name));
        $holder = UserAccount::all()->first(fn (UserAccount $candidate) =>
            strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $candidate->name)) === $normalized
        );
        if ($holder) {
            return $holder->id;
        }

        return UserAccount::create([
            'code' => 'LEGACY-HOLDER-'.strtoupper(substr(sha1($normalized), 0, 12)),
            'type' => str_contains(strtoupper($name), 'TRADING') ? 'company' : 'individual',
            'name' => $name,
            'is_active' => true,
        ])->id;
    }

    private function historicalReceiptInstitution(string $name): ?FinancialInstitution
    {
        $name = Str::upper($name);
        $code = match (true) {
            str_contains($name, 'GCASH') => 'GCASH',
            str_contains($name, 'MAYA') => 'MAYA',
            str_contains($name, 'BPI') => 'BPI',
            str_contains($name, 'BDO') => 'BDO',
            str_contains($name, 'CASH') => 'CASH',
            default => null,
        };

        return $code ? FinancialInstitution::where('code', $code)->first() : null;
    }

    private function accountKind(object $row): string
    {
        return match ((int) $row->payment_term_id) {
            1 => 'cash',
            2 => in_array((int) $row->bank_id, [2, 6], true) ? 'e_wallet' : 'bank_account',
            3 => 'checking_account',
            4 => 'credit_card',
            default => throw new RuntimeException("Unknown payment term {$row->payment_term_id} on account {$row->id}."),
        };
    }

    private function displayName(object $row): string
    {
        $description = trim((string) $row->account_description);
        $holder = trim((string) $row->account_name);
        $label = $description ?: ($holder ?: 'Legacy account '.$row->id);

        return trim($row->bank_name).' - '.$label;
    }

    private function ledgerName(object $row): string
    {
        $number = trim((string) $row->account_number);
        $suffix = $number !== '' && $number !== '0' ? ' •••• '.substr($number, -4) : '';

        return Str::limit($this->displayName($row).$suffix, 255, '');
    }

    private function institutionCode(string $name): string
    {
        return Str::upper(Str::slug(trim($name), '_'));
    }

    private function institutionType(int $legacyId): string
    {
        return match ($legacyId) {
            1 => 'cash',
            2, 6 => 'e_wallet',
            default => 'bank',
        };
    }
}
