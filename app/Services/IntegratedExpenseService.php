<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegratedExpenseService
{
    public function __construct(private PaymentPostingService $posting) {}

    public function record(array $data, ?int $userId): array
    {
        return DB::transaction(function () use ($data, $userId) {
            if (! empty($data['idempotency_key'])) {
                $existingPayment = Payment::where('idempotency_key', $data['idempotency_key'])->first();
                if ($existingPayment?->expense) {
                    return $this->summary($existingPayment->expense);
                }
            }

            $branch = $this->branch($data);
            $chartAccount = ChartAccount::updateOrCreate(
                [
                    'legacy_source' => $data['source'].'.chart_of_accounts',
                    'legacy_id' => $data['external_chart_account_id'],
                ],
                [
                    'code' => $this->sourceCode('EXP', $data['source'], $data['external_chart_account_id']),
                    'name' => $data['chart_account_name'],
                    'type' => 'expense',
                    'is_active' => true,
                ]
            );

            $expense = Expense::firstOrNew([
                'legacy_source' => $data['source'].'.expenses_transaction',
                'legacy_id' => $data['external_expense_id'],
            ]);
            $alreadyPaid = $expense->exists
                ? (float) $expense->payments()->where('status', 'posted')->sum('amount')
                : 0;
            if ((float) $data['amount'] < $alreadyPaid) {
                throw ValidationException::withMessages(['amount' => 'The updated expense amount is below its posted payments.']);
            }
            $expense->fill([
                'branch_id' => $branch->id,
                'number' => $this->sourceCode('EX', $data['source'], $data['external_expense_id']),
                'chart_account_id' => $chartAccount->id,
                'expense_date' => $data['expense_date'],
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'description' => trim($data['expense_name'].(! empty($data['description']) ? ': '.$data['description'] : '')),
            ])->save();

            if (! $data['record_payment']) {
                return $this->summary($expense->fresh());
            }
            if (in_array($expense->status, ['paid', 'cancelled'], true)) {
                throw ValidationException::withMessages(['external_expense_id' => 'This central expense is already closed.']);
            }

            $account = FinancialAccount::with('applications')->findOrFail($data['financial_account_id']);
            $method = PaymentMethod::findOrFail($data['payment_method_id']);
            $this->assertPaymentSelection($account, $method, $branch, $data['currency'], (int) ($data['installment_count'] ?? 1));
            $balance = bcsub((string) $expense->amount, (string) $alreadyPaid, 4);
            if (bccomp((string) $data['amount'], $balance, 4) === 1) {
                throw ValidationException::withMessages(['amount' => 'The payment exceeds the central expense balance.']);
            }

            $payment = Payment::create([
                'number' => 'PAY-'.Str::upper((string) Str::ulid()),
                'branch_id' => $branch->id,
                'type' => 'expense',
                'direction' => 'outflow',
                'expense_id' => $expense->id,
                'offset_account_id' => $chartAccount->id,
                'payment_date' => $data['payment_date'],
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'idempotency_key' => $data['idempotency_key'],
                'memo' => 'Expense payment from '.$data['source'],
                'created_by' => $userId,
            ]);
            $payment->lines()->create([
                'financial_account_id' => $account->id,
                'payment_method_id' => $method->id,
                'amount' => $data['amount'],
                'installment_count' => $data['installment_count'] ?? 1,
                'reference' => $data['reference'] ?? null,
            ]);
            $this->posting->post($payment, $userId);

            return $this->summary($expense->fresh());
        });
    }

    public function findMany(string $source, array $externalExpenseIds): array
    {
        return Expense::where('legacy_source', $source.'.expenses_transaction')
            ->whereIn('legacy_id', $externalExpenseIds)
            ->with($this->summaryRelations())
            ->get()
            ->mapWithKeys(fn (Expense $expense) => [
                (string) $expense->legacy_id => $this->summary($expense),
            ])
            ->all();
    }

    public function cancel(string $source, int $externalExpenseId, string $reason, ?int $userId): array
    {
        return DB::transaction(function () use ($source, $externalExpenseId, $reason, $userId) {
            $expense = Expense::query()
                ->where('legacy_source', $source.'.expenses_transaction')
                ->where('legacy_id', $externalExpenseId)
                ->lockForUpdate()
                ->first();

            // A legacy-only expense may never have been synchronized. Treat that as
            // an idempotent cancellation so the branch is still allowed to delete it.
            if (! $expense) {
                return [
                    'found' => false,
                    'cancelled' => false,
                    'external_expense_id' => $externalExpenseId,
                ];
            }

            if ($expense->status === 'cancelled') {
                return array_merge(['found' => true, 'cancelled' => true], $this->summary($expense));
            }

            foreach ($expense->payments()->where('status', 'posted')->orderBy('id')->get() as $payment) {
                $this->posting->void($payment, $reason, $userId);
            }

            $expense->update(['status' => 'cancelled']);

            return array_merge(['found' => true, 'cancelled' => true], $this->summary($expense->fresh()));
        });
    }

    private function branch(array $data): Branch
    {
        $legacySource = $data['source'].'.shop';
        $branch = Branch::where('legacy_source', $legacySource)->where('legacy_id', $data['external_branch_id'])->first();
        if ($branch) {
            $branch->update(['name' => $data['branch_name'], 'is_active' => true]);
            return $branch;
        }

        $unmapped = Branch::where('name', $data['branch_name'])->whereNull('legacy_source')->first();
        if ($unmapped) {
            $unmapped->update(['legacy_source' => $legacySource, 'legacy_id' => $data['external_branch_id']]);
            return $unmapped;
        }

        $key = substr(strtoupper(dechex(crc32($data['source']))).$data['external_branch_id'], 0, 12);
        $inventory = ChartAccount::firstOrCreate(['code' => "BR-{$key}-INV"], ['name' => $data['branch_name'].' Inventory', 'type' => 'asset']);
        $dueFrom = ChartAccount::firstOrCreate(['code' => "BR-{$key}-DF"], ['name' => $data['branch_name'].' Due From', 'type' => 'asset']);
        $dueTo = ChartAccount::firstOrCreate(['code' => "BR-{$key}-DT"], ['name' => $data['branch_name'].' Due To', 'type' => 'liability']);

        return Branch::create([
            'code' => $this->sourceCode('BR', $data['source'], $data['external_branch_id']),
            'name' => Branch::where('name', $data['branch_name'])->exists() ? $data['branch_name'].' '.$data['external_branch_id'] : $data['branch_name'],
            'inventory_account_id' => $inventory->id,
            'due_from_account_id' => $dueFrom->id,
            'due_to_account_id' => $dueTo->id,
            'is_active' => true,
            'legacy_source' => $legacySource,
            'legacy_id' => $data['external_branch_id'],
        ]);
    }

    private function assertPaymentSelection(FinancialAccount $account, PaymentMethod $method, Branch $branch, string $currency, int $installmentCount): void
    {
        $applications = $account->applications->pluck('application');
        if (! $account->is_active || ! $account->usable_for_payments || $account->currency !== $currency
            || ! $account->isAvailableToBranch($branch->id)
            || ($applications->isNotEmpty() && ! $applications->contains(FinancialAccountApplication::EXPENSE_PAYMENT))) {
            throw ValidationException::withMessages(['financial_account_id' => 'This account cannot pay this expense.']);
        }
        $allowedKinds = match ($method->type) {
            'cash' => ['cash'],
            'bank_transfer' => ['bank_account', 'checking_account'],
            'cheque' => ['checking_account'],
            'credit_card' => ['credit_card'],
            'e_wallet' => ['e_wallet'],
            default => [],
        };
        if (! $method->is_active || ! in_array($account->kind, $allowedKinds, true)) {
            throw ValidationException::withMessages(['payment_method_id' => 'The payment method is incompatible with the selected account.']);
        }
        if ($installmentCount > 1 && $account->kind !== 'credit_card') {
            throw ValidationException::withMessages(['installment_count' => 'Installments require a credit-card account.']);
        }
    }

    private function summary(Expense $expense): array
    {
        $expense->loadMissing($this->summaryRelations());
        $paid = $expense->payments->where('status', 'posted')->sum(fn ($payment) => (float) $payment->amount);

        return [
            'expense' => $expense,
            'data' => $expense->payments->values(),
            'total_payment' => number_format($paid, 4, '.', ''),
            'balance' => number_format(max(0, (float) $expense->amount - $paid), 4, '.', ''),
        ];
    }

    private function summaryRelations(): array
    {
        return [
            'branch', 'chartAccount',
            'payments' => fn ($query) => $query
                ->with('lines.financialAccount.institution', 'lines.paymentMethod', 'lines.obligationCharges.obligation')
                ->latest('payment_date')->latest('id'),
        ];
    }

    private function sourceCode(string $prefix, string $source, int $id): string
    {
        return substr($prefix.'-'.strtoupper(dechex(crc32($source))).'-'.$id, 0, 40);
    }
}
