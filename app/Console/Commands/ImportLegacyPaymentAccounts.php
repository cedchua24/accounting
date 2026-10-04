<?php

namespace App\Console\Commands;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\FinancialInstitution;
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

        $this->info("Found {$banks->count()} banks and {$accounts->count()} payment accounts.");
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
            $this->comment('Dry run complete. Legacy balances were intentionally excluded.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($source, $banks, $accounts, $usage) {
            foreach ($banks as $bank) {
                $this->importInstitution($source, $bank);
            }

            foreach ($accounts as $account) {
                $this->importAccount($source, $account, $usage);
            }
        });

        $this->info("Imported {$banks->count()} financial institutions and {$accounts->count()} financial accounts.");
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
    }

    private function importAccount(string $source, object $row, array $usage): void
    {
        $account = FinancialAccount::where([
            'legacy_source' => $source.'.payment_type_po',
            'legacy_id' => (int) $row->id,
        ])->first();

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
            'financial_institution_id' => (int) $row->bank_id,
            'chart_account_id' => $ledger->id,
            'kind' => $kind,
            'name' => $this->displayName($row),
            'account_number' => $kind === 'cash' ? null : trim((string) $row->account_number),
            'account_holder' => trim((string) $row->account_name) ?: null,
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

        $account->applications()->where('source', 'legacy_import')->whereNotIn('application', $applications)->delete();
        foreach ($applications as $application) {
            $account->applications()->firstOrCreate(
                ['application' => $application],
                ['source' => 'legacy_import']
            );
        }
    }

    private function applicationsFor(object $row, array $usage): array
    {
        $applications = collect();
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
