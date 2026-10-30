<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Counterparty;
use App\Models\CustomerRefund;
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
            if ($invoice->exists && in_array($invoice->status, ['paid', 'refunded', 'cancelled'], true)) {
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

    public function findMany(string $source, array $externalOrderIds): array
    {
        if ($externalOrderIds === []) {
            return [];
        }

        return SalesInvoice::where('legacy_source', $source.'.shop_order_transaction')
            ->whereIn('legacy_id', $externalOrderIds)
            ->with($this->summaryRelations())
            ->get()
            ->mapWithKeys(fn (SalesInvoice $invoice) => [
                (string) $invoice->legacy_id => $this->summary($invoice),
            ])
            ->all();
    }

    public function refundCandidates(string $source, array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        $shopOrders = collect($orders)->keyBy(fn (array $order) => (string) $order['external_order_id']);

        return SalesInvoice::query()
            ->where('legacy_source', $source.'.shop_order_transaction')
            ->whereIn('legacy_id', $shopOrders->keys())
            ->with([
                'payments' => fn ($query) => $query->where('status', 'posted'),
                'refunds' => fn ($query) => $query->where('status', 'posted'),
            ])
            ->get()
            ->map(function (SalesInvoice $invoice) use ($shopOrders) {
                $order = $shopOrders->get((string) $invoice->legacy_id);
                $received = $invoice->payments->reduce(
                    fn (string $total, Payment $payment) => bcadd($total, (string) $payment->amount, 4),
                    '0.0000',
                );
                $refunded = $invoice->refunds->reduce(
                    fn (string $total, CustomerRefund $refund) => bcadd($total, (string) $refund->amount, 4),
                    '0.0000',
                );
                $totalAccount = bcsub($received, $refunded, 4);
                // Keep money as decimal strings so large values and fractions are
                // compared without passing through floating-point arithmetic.
                $totalAmount = bcadd((string) $order['order_total'], '0', 4);

                if (bccomp($totalAccount, $totalAmount, 4) !== 1) {
                    return null;
                }

                $payment = $invoice->payments->sortByDesc('id')->first();

                return [
                    'transaction_id' => (int) $invoice->legacy_id,
                    'date' => $order['order_date'],
                    'total_amount' => $totalAmount,
                    'total_account' => $totalAccount,
                    'refund_amount' => bcsub($totalAccount, $totalAmount, 4),
                    'total_received' => $received,
                    'total_refunded' => $refunded,
                    'sales_invoice_id' => $invoice->id,
                    'original_payment_id' => $payment?->id,
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $candidate) => $candidate['date'].'|'.str_pad((string) $candidate['transaction_id'], 12, '0', STR_PAD_LEFT))
            ->values()
            ->all();
    }

    public function refundCandidatesFromLegacy(
        string $source,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $candidates = [];
        $query = DB::connection('legacy_mysql')
            ->table('shop_order_transaction as sot')
            ->join('shop', 'shop.id', '=', 'sot.shop_id')
            ->where('shop.shop_type_id', 3)
            ->when($dateFrom, fn ($query, $date) => $query->whereDate('sot.date', '>=', $date))
            ->when($dateTo, fn ($query, $date) => $query->whereDate('sot.date', '<=', $date))
            ->select([
                'sot.id as external_order_id',
                'sot.date as order_date',
                'sot.shop_order_transaction_total_price as order_total',
            ])
            ->orderBy('sot.id');

        $query->chunkById(500, function ($orders) use ($source, &$candidates) {
            $batch = $orders->map(fn ($order) => [
                'external_order_id' => (int) $order->external_order_id,
                'order_date' => (string) $order->order_date,
                'order_total' => (string) $order->order_total,
            ])->all();

            array_push($candidates, ...$this->refundCandidates($source, $batch));
        }, 'sot.id', 'external_order_id');

        return collect($candidates)
            ->sortByDesc(fn (array $candidate) => $candidate['date'].'|'.str_pad((string) $candidate['transaction_id'], 12, '0', STR_PAD_LEFT))
            ->values()
            ->all();
    }

    public function paymentReport(string $source, string $dateFrom, string $dateTo): array
    {
        $days = [];
        $ensureDay = function (string $date) use (&$days): void {
            if (! isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'gross_received' => 0.0,
                    'refunds' => 0.0,
                    'total_cash' => 0.0,
                    'total_online' => 0.0,
                    'total_sales' => 0.0,
                    'payment_count' => 0,
                    'order_ids' => [],
                    'accounts' => [],
                    'customers' => [],
                    'orders' => [],
                ];
            }
        };
        $accountKey = fn (int $accountId, int $methodId): string => $accountId.'-'.$methodId;

        $payments = Payment::query()
            ->where('type', 'customer')
            ->where('status', 'posted')
            ->whereDate('payment_date', '>=', $dateFrom)
            ->whereDate('payment_date', '<=', $dateTo)
            ->whereHas('salesInvoice', fn ($query) => $query->where('legacy_source', $source.'.shop_order_transaction'))
            ->with('salesInvoice.customer', 'lines.financialAccount.institution', 'lines.paymentMethod')
            ->get();

        foreach ($payments as $payment) {
            $date = $payment->payment_date->toDateString();
            $ensureDay($date);
            $days[$date]['payment_count']++;
            $externalOrderId = (string) $payment->salesInvoice->legacy_id;
            $externalCustomerId = (string) ($payment->salesInvoice->customer?->legacy_id ?? '');
            $days[$date]['order_ids'][$externalOrderId] = true;
            if ($externalCustomerId !== '') {
                $days[$date]['customers'][$externalCustomerId] ??= [
                    'external_customer_id' => (int) $externalCustomerId,
                    'gross_received' => 0.0,
                    'refunds' => 0.0,
                    'total_sales' => 0.0,
                ];
                $days[$date]['customers'][$externalCustomerId]['gross_received'] += (float) $payment->amount;
            }
            $days[$date]['orders'][$externalOrderId] ??= [
                'external_order_id' => (int) $externalOrderId,
                'external_customer_id' => $externalCustomerId !== '' ? (int) $externalCustomerId : null,
                'gross_received' => 0.0,
                'refunds' => 0.0,
                'total_sales' => 0.0,
            ];
            $days[$date]['orders'][$externalOrderId]['gross_received'] += (float) $payment->amount;

            foreach ($payment->lines as $line) {
                $amount = (float) $line->amount;
                $isCash = $line->paymentMethod->type === 'cash' || $line->financialAccount->kind === 'cash';
                $days[$date]['gross_received'] += $amount;
                $days[$date][$isCash ? 'total_cash' : 'total_online'] += $amount;
                $key = $accountKey($line->financial_account_id, $line->payment_method_id);
                if (! isset($days[$date]['accounts'][$key])) {
                    $days[$date]['accounts'][$key] = [
                        'financial_account_id' => $line->financial_account_id,
                        'payment_method_id' => $line->payment_method_id,
                        'payment_method' => $line->paymentMethod->name,
                        'account_name' => $line->financialAccount->name,
                        'institution_name' => $line->financialAccount->institution?->name,
                        'masked_account_number' => $line->financialAccount->masked_account_number,
                        'kind' => $line->financialAccount->kind,
                        'amount' => 0.0,
                    ];
                }
                $days[$date]['accounts'][$key]['amount'] += $amount;
            }
        }

        $refunds = CustomerRefund::query()
            ->where('status', 'posted')
            ->whereDate('refund_date', '>=', $dateFrom)
            ->whereDate('refund_date', '<=', $dateTo)
            ->whereHas('salesInvoice', fn ($query) => $query->where('legacy_source', $source.'.shop_order_transaction'))
            ->with('salesInvoice.customer', 'financialAccount.institution', 'paymentMethod')
            ->get();

        foreach ($refunds as $refund) {
            $date = $refund->refund_date->toDateString();
            $ensureDay($date);
            $amount = (float) $refund->amount;
            $externalOrderId = (string) $refund->salesInvoice->legacy_id;
            $externalCustomerId = (string) ($refund->salesInvoice->customer?->legacy_id ?? '');
            $isCash = $refund->paymentMethod->type === 'cash' || $refund->financialAccount->kind === 'cash';
            $days[$date]['refunds'] += $amount;
            if ($externalCustomerId !== '') {
                $days[$date]['customers'][$externalCustomerId] ??= [
                    'external_customer_id' => (int) $externalCustomerId,
                    'gross_received' => 0.0,
                    'refunds' => 0.0,
                    'total_sales' => 0.0,
                ];
                $days[$date]['customers'][$externalCustomerId]['refunds'] += $amount;
            }
            $days[$date]['orders'][$externalOrderId] ??= [
                'external_order_id' => (int) $externalOrderId,
                'external_customer_id' => $externalCustomerId !== '' ? (int) $externalCustomerId : null,
                'gross_received' => 0.0,
                'refunds' => 0.0,
                'total_sales' => 0.0,
            ];
            $days[$date]['orders'][$externalOrderId]['refunds'] += $amount;
            $days[$date][$isCash ? 'total_cash' : 'total_online'] -= $amount;
            $key = $accountKey($refund->financial_account_id, $refund->payment_method_id);
            if (! isset($days[$date]['accounts'][$key])) {
                $days[$date]['accounts'][$key] = [
                    'financial_account_id' => $refund->financial_account_id,
                    'payment_method_id' => $refund->payment_method_id,
                    'payment_method' => $refund->paymentMethod->name,
                    'account_name' => $refund->financialAccount->name,
                    'institution_name' => $refund->financialAccount->institution?->name,
                    'masked_account_number' => $refund->financialAccount->masked_account_number,
                    'kind' => $refund->financialAccount->kind,
                    'amount' => 0.0,
                ];
            }
            $days[$date]['accounts'][$key]['amount'] -= $amount;
        }

        ksort($days);
        $data = collect($days)->map(function (array $day) {
            $day['total_sales'] = $day['gross_received'] - $day['refunds'];
            $day['total_count'] = count($day['order_ids']);
            unset($day['order_ids']);
            $day['accounts'] = array_values($day['accounts']);

            foreach (['gross_received', 'refunds', 'total_cash', 'total_online', 'total_sales'] as $field) {
                $day[$field] = number_format($day[$field], 4, '.', '');
            }
            foreach ($day['accounts'] as &$account) {
                $account['amount'] = number_format($account['amount'], 4, '.', '');
            }
            $day['customers'] = array_values($day['customers']);
            foreach ($day['customers'] as &$customer) {
                $customer['total_sales'] = $customer['gross_received'] - $customer['refunds'];
                foreach (['gross_received', 'refunds', 'total_sales'] as $field) {
                    $customer[$field] = number_format($customer[$field], 4, '.', '');
                }
            }
            $day['orders'] = array_values($day['orders']);
            foreach ($day['orders'] as &$order) {
                $order['total_sales'] = $order['gross_received'] - $order['refunds'];
                foreach (['gross_received', 'refunds', 'total_sales'] as $field) {
                    $order[$field] = number_format($order[$field], 4, '.', '');
                }
            }

            return $day;
        })->values();

        return [
            'data' => $data,
            'total_cash' => number_format($data->sum(fn ($day) => (float) $day['total_cash']), 4, '.', ''),
            'total_online' => number_format($data->sum(fn ($day) => (float) $day['total_online']), 4, '.', ''),
            'total_sales' => number_format($data->sum(fn ($day) => (float) $day['total_sales']), 4, '.', ''),
            'total_refunded' => number_format($data->sum(fn ($day) => (float) $day['refunds']), 4, '.', ''),
        ];
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
        $invoice->loadMissing($this->summaryRelations());
        $received = $invoice->payments->where('status', 'posted')->sum(fn ($payment) => (float) $payment->amount);
        $refunded = $invoice->refunds->where('status', 'posted')->sum(fn ($refund) => (float) $refund->amount);
        $netReceived = max(0, $received - $refunded);
        $netTotal = max(0, (float) $invoice->total_amount - $refunded);
        $invoice->payments->each(function ($payment) {
            $refunded = $payment->refunds->where('status', 'posted')->sum(fn ($refund) => (float) $refund->amount);
            $payment->setAttribute('refunded_amount', number_format($refunded, 4, '.', ''));
            $payment->setAttribute('refundable_amount', number_format(max(0, (float) $payment->amount - $refunded), 4, '.', ''));
        });

        $paymentEntries = $invoice->payments
            ->where('status', 'posted')
            ->map(function (Payment $payment) use ($invoice) {
                $line = $payment->lines->first();

                return [
                    '_sort' => $payment->payment_date->format('Y-m-d').'|0|'.str_pad((string) $payment->id, 12, '0', STR_PAD_LEFT),
                    'type' => 'payment',
                    'id' => $payment->id,
                    'transaction_id' => $invoice->legacy_id,
                    'transaction_number' => $invoice->number,
                    'linked_payment_id' => null,
                    'number' => $payment->number,
                    'date' => $payment->payment_date->toDateString(),
                    'amount' => number_format((float) $payment->amount, 4, '.', ''),
                    'gross_amount' => number_format((float) $payment->amount, 4, '.', ''),
                    'status' => $payment->status,
                    'reference' => $line?->reference,
                    'financial_account' => $line?->financialAccount,
                    'payment_method' => $line?->paymentMethod,
                ];
            })
            ->concat($invoice->refunds->where('status', 'posted')->map(fn (CustomerRefund $refund) => [
                '_sort' => $refund->refund_date->format('Y-m-d').'|1|'.str_pad((string) $refund->id, 12, '0', STR_PAD_LEFT),
                'type' => 'refund',
                'id' => $refund->id,
                'transaction_id' => $invoice->legacy_id,
                'transaction_number' => $invoice->number,
                'linked_payment_id' => $refund->original_payment_id,
                'number' => $refund->number,
                'date' => $refund->refund_date->toDateString(),
                'amount' => number_format(-((float) $refund->amount), 4, '.', ''),
                'gross_amount' => number_format((float) $refund->amount, 4, '.', ''),
                'status' => $refund->status,
                'reference' => $refund->reference,
                'financial_account' => $refund->financialAccount,
                'payment_method' => $refund->paymentMethod,
            ]))
            ->sortBy('_sort')
            ->values()
            ->map(function (array $entry) {
                unset($entry['_sort']);

                return $entry;
            });

        return [
            'invoice' => $invoice,
            'data' => $invoice->payments->values(),
            'payment_entries' => $paymentEntries,
            'refunds' => $invoice->refunds->values(),
            'original_total' => number_format((float) $invoice->total_amount, 4, '.', ''),
            'total_received' => number_format($received, 4, '.', ''),
            'total_refunded' => number_format($refunded, 4, '.', ''),
            'net_total' => number_format($netTotal, 4, '.', ''),
            'total_payment' => number_format($netReceived, 4, '.', ''),
            'balance' => number_format(max(0, $netTotal - $netReceived), 4, '.', ''),
        ];
    }

    private function summaryRelations(): array
    {
        return [
            'branch', 'customer',
            'payments' => fn ($query) => $query->with('lines.financialAccount.institution', 'lines.paymentMethod', 'refunds')->latest('payment_date')->latest('id'),
            'refunds' => fn ($query) => $query->with('financialAccount.institution', 'paymentMethod', 'originalPayment')->latest('refund_date')->latest('id'),
        ];
    }

    private function sourceCode(string $prefix, string $source, int $id): string
    {
        return substr($prefix.'-'.strtoupper(dechex(crc32($source))).'-'.$id, 0, 40);
    }
}
