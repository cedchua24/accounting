<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Counterparty;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegratedPurchaseOrderService
{
    public function __construct(private PaymentPostingService $posting) {}

    public function recordPayment(array $data, ?int $userId): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $existing = Payment::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->summary($existing->purchaseOrder);
            }

            $branch = $this->branch($data);
            $supplier = Counterparty::updateOrCreate(
                ['legacy_source' => $data['source'].'.supplier', 'legacy_id' => $data['external_supplier_id']],
                [
                    'type' => 'supplier',
                    'code' => $this->sourceCode('SUP', $data['source'], $data['external_supplier_id']),
                    'name' => $data['supplier_name'],
                    'is_active' => true,
                ]
            );
            $payable = ChartAccount::firstOrCreate(
                ['code' => 'AP-TRADE'],
                ['name' => 'Trade Accounts Payable', 'type' => 'liability', 'is_active' => true]
            );
            $purchaseOrder = PurchaseOrder::firstOrNew([
                'legacy_source' => $data['source'].'.order_supplier_transaction',
                'legacy_id' => $data['external_order_id'],
            ]);
            $paid = $purchaseOrder->exists
                ? (float) $purchaseOrder->payments()->where('status', 'posted')->sum('amount')
                : 0;
            if ($purchaseOrder->exists && in_array($purchaseOrder->status, ['paid', 'cancelled'], true)) {
                throw ValidationException::withMessages(['external_order_id' => 'This central purchase order is already closed.']);
            }
            if ((float) $data['order_total'] < $paid) {
                throw ValidationException::withMessages(['order_total' => 'The updated order total is below its posted payments.']);
            }
            $purchaseOrder->fill([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'number' => ($data['order_number'] ?? null) ?: $this->sourceCode('PO', $data['source'], $data['external_order_id']),
                'order_date' => $data['order_date'],
                'currency' => $data['currency'],
                'total_amount' => $data['order_total'],
                'description' => 'Imported from '.$data['source'].' supplier order '.$data['external_order_id'],
            ])->save();

            $account = FinancialAccount::with('applications')->findOrFail($data['financial_account_id']);
            $method = PaymentMethod::findOrFail($data['payment_method_id']);
            $this->assertPaymentSelection($account, $method, $branch, $data['currency'], (int) ($data['installment_count'] ?? 1));
            $balance = bcsub((string) $purchaseOrder->total_amount, (string) $paid, 4);
            if (bccomp((string) $data['amount'], $balance, 4) === 1) {
                throw ValidationException::withMessages(['amount' => 'The payment exceeds the central purchase-order balance.']);
            }

            $payment = Payment::create([
                'number' => 'PAY-'.Str::upper((string) Str::ulid()),
                'branch_id' => $branch->id,
                'type' => 'purchase_order',
                'direction' => 'outflow',
                'counterparty_id' => $supplier->id,
                'purchase_order_id' => $purchaseOrder->id,
                'offset_account_id' => $payable->id,
                'payment_date' => $data['payment_date'],
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'idempotency_key' => $data['idempotency_key'],
                'memo' => 'Supplier payment from '.$data['source'],
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

            return $this->summary($purchaseOrder->fresh());
        });
    }

    public function find(string $source, int $externalOrderId): ?array
    {
        $purchaseOrder = PurchaseOrder::where('legacy_source', $source.'.order_supplier_transaction')
            ->where('legacy_id', $externalOrderId)
            ->with($this->summaryRelations())
            ->first();

        return $purchaseOrder ? $this->summary($purchaseOrder) : null;
    }

    public function findMany(string $source, array $externalOrderIds): array
    {
        return PurchaseOrder::where('legacy_source', $source.'.order_supplier_transaction')
            ->whereIn('legacy_id', $externalOrderIds)
            ->with($this->summaryRelations())
            ->get()
            ->mapWithKeys(fn (PurchaseOrder $purchaseOrder) => [
                (string) $purchaseOrder->legacy_id => $this->summary($purchaseOrder),
            ])
            ->all();
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
            || ($applications->isNotEmpty() && ! $applications->contains(FinancialAccountApplication::PURCHASE_ORDER_PAYMENT))) {
            throw ValidationException::withMessages(['financial_account_id' => 'This account cannot pay this purchase order.']);
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

    private function summary(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing($this->summaryRelations());
        $posted = $purchaseOrder->payments->where('status', 'posted')->sum(fn ($payment) => (float) $payment->amount);

        return [
            'purchase_order' => $purchaseOrder,
            'data' => $purchaseOrder->payments->values(),
            'total_payment' => number_format($posted, 4, '.', ''),
            'balance' => number_format(max(0, (float) $purchaseOrder->total_amount - $posted), 4, '.', ''),
        ];
    }

    private function summaryRelations(): array
    {
        return [
            'branch', 'supplier',
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
