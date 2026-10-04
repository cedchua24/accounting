<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Counterparty;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountApplication;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegratedCustomerOrderService
{
    public function __construct(private PaymentPostingService $posting) {}

    public function recordPayment(array $data, ?int $userId): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $existing = Payment::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->summary($existing->salesInvoice);
            }

            $branch = $this->branch($data);
            $customer = Counterparty::updateOrCreate(
                ['legacy_source' => $data['source'].'.customer', 'legacy_id' => $data['external_customer_id']],
                [
                    'type' => 'customer',
                    'code' => $this->sourceCode('CUS', $data['source'], $data['external_customer_id']),
                    'name' => $data['customer_name'], 'is_active' => true,
                ]
            );
            $receivable = ChartAccount::firstOrCreate(
                ['code' => 'AR-TRADE'],
                ['name' => 'Trade Accounts Receivable', 'type' => 'asset', 'is_active' => true]
            );
            $invoice = SalesInvoice::firstOrNew([
                'legacy_source' => $data['source'].'.shop_order_transaction',
                'legacy_id' => $data['external_order_id'],
            ]);
            $paid = $invoice->exists ? (float) $invoice->payments()->where('status', 'posted')->sum('amount') : 0;
            if ($invoice->exists && in_array($invoice->status, ['paid', 'cancelled'], true)) {
                throw ValidationException::withMessages(['external_order_id' => 'This central customer order is already closed.']);
            }
            if ((float) $data['order_total'] < $paid) {
                throw ValidationException::withMessages(['order_total' => 'The updated order total is below its posted payments.']);
            }
            $invoice->fill([
                'branch_id' => $branch->id, 'customer_id' => $customer->id,
                'number' => ($data['order_number'] ?? null) ?: $this->sourceCode('SO', $data['source'], $data['external_order_id']),
                'invoice_date' => $data['order_date'], 'currency' => $data['currency'],
                'total_amount' => $data['order_total'],
                'description' => 'Imported from '.$data['source'].' shop order '.$data['external_order_id'],
            ])->save();

            $account = FinancialAccount::with('applications', 'chartAccount')->findOrFail($data['financial_account_id']);
            $method = PaymentMethod::findOrFail($data['payment_method_id']);
            $this->assertPaymentSelection($account, $method, $branch, $data['currency']);
            $balance = bcsub((string) $invoice->total_amount, (string) $paid, 4);
            if (bccomp((string) $data['amount'], $balance, 4) === 1) {
                throw ValidationException::withMessages(['amount' => 'The payment exceeds the central order balance.']);
            }

            $payment = Payment::create([
                'number' => 'PAY-'.Str::upper((string) Str::ulid()),
                'branch_id' => $branch->id, 'type' => 'customer', 'direction' => 'inflow',
                'counterparty_id' => $customer->id, 'sales_invoice_id' => $invoice->id,
                'offset_account_id' => $receivable->id, 'payment_date' => $data['payment_date'],
                'currency' => $data['currency'], 'amount' => $data['amount'],
                'idempotency_key' => $data['idempotency_key'],
                'memo' => 'Customer-order payment from '.$data['source'], 'created_by' => $userId,
            ]);
            $payment->lines()->create([
                'financial_account_id' => $account->id, 'payment_method_id' => $method->id,
                'amount' => $data['amount'], 'reference' => $data['reference'] ?? null,
            ]);
            $this->posting->post($payment, $userId);

            return $this->summary($invoice->fresh());
        });
    }

    public function find(string $source, int $externalOrderId): ?array
    {
        $invoice = SalesInvoice::where('legacy_source', $source.'.shop_order_transaction')
            ->where('legacy_id', $externalOrderId)->first();

        return $invoice ? $this->summary($invoice) : null;
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
            'name' => Branch::where('name', $data['branch_name'])->exists()
                ? $data['branch_name'].' '.$data['external_branch_id'] : $data['branch_name'],
            'inventory_account_id' => $inventory->id, 'due_from_account_id' => $dueFrom->id,
            'due_to_account_id' => $dueTo->id, 'is_active' => true,
            'legacy_source' => $legacySource, 'legacy_id' => $data['external_branch_id'],
        ]);
    }

    private function assertPaymentSelection(FinancialAccount $account, PaymentMethod $method, Branch $branch, string $currency): void
    {
        $applications = $account->applications->pluck('application');
        if (! $account->is_active || ! $account->usable_for_receipts || $account->currency !== $currency
            || ! $account->isAvailableToBranch($branch->id) || $account->kind === 'credit_card'
            || ($applications->isNotEmpty() && ! $applications->contains(FinancialAccountApplication::CUSTOMER_RECEIPT))) {
            throw ValidationException::withMessages(['financial_account_id' => 'This account cannot receive this customer payment.']);
        }
        if (! $method->is_active || $method->type === 'credit_card') {
            throw ValidationException::withMessages(['payment_method_id' => 'Select an active receipt payment method.']);
        }
    }

    private function summary(SalesInvoice $invoice): array
    {
        $invoice->load(['branch', 'customer', 'payments' => fn ($query) => $query->with('lines.financialAccount.institution', 'lines.paymentMethod')->latest('payment_date')->latest('id')]);
        $posted = $invoice->payments->where('status', 'posted')->sum(fn ($payment) => (float) $payment->amount);

        return [
            'invoice' => $invoice,
            'data' => $invoice->payments->values(),
            'total_payment' => number_format($posted, 4, '.', ''),
            'balance' => number_format(max(0, (float) $invoice->total_amount - $posted), 4, '.', ''),
        ];
    }

    private function sourceCode(string $prefix, string $source, int $id): string
    {
        return substr($prefix.'-'.strtoupper(dechex(crc32($source))).'-'.$id, 0, 40);
    }
}
