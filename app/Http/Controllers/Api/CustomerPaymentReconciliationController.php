<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\PaymentLine;
use Illuminate\Http\Request;

class CustomerPaymentReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'order_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $account = FinancialAccount::with('institution')->findOrFail($data['financial_account_id']);
        $lines = PaymentLine::query()
            ->where('financial_account_id', $account->id)
            ->whereHas('payment', fn ($query) => $query
                ->where('type', 'customer')
                ->where('direction', 'inflow')
                ->where('status', 'posted')
                ->whereHas('salesInvoice', fn ($invoices) => $invoices
                    ->where('legacy_source', $data['source'].'.shop_order_transaction')
                    ->whereDate('invoice_date', $data['order_date'])))
            ->with(['payment.branch', 'payment.counterparty', 'payment.salesInvoice', 'paymentMethod', 'confirmedBy'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'account' => $account,
            'date' => $data['order_date'],
            'total_amount' => number_format($lines->sum(fn ($line) => (float) $line->amount), 4, '.', ''),
            'confirmed_amount' => number_format($lines->whereNotNull('confirmed_at')->sum(fn ($line) => (float) $line->amount), 4, '.', ''),
            'total_count' => $lines->count(),
            'confirmed_count' => $lines->whereNotNull('confirmed_at')->count(),
            'data' => $lines->map(fn (PaymentLine $line) => [
                'id' => $line->id,
                'payment_id' => $line->payment_id,
                'external_order_id' => $line->payment->salesInvoice?->legacy_id,
                'branch_name' => $line->payment->branch?->name,
                'customer_name' => $line->payment->counterparty?->name,
                'amount' => $line->amount,
                'order_total' => $line->payment->salesInvoice?->total_amount,
                'payment_date' => $line->payment->payment_date?->format('Y-m-d'),
                'payment_status' => $line->payment->status,
                'payment_method' => $line->paymentMethod,
                'confirmed_at' => $line->confirmed_at,
                'confirmed_by' => $line->confirmedBy,
            ])->values(),
        ]);
    }

    public function update(Request $request, PaymentLine $paymentLine)
    {
        $data = $request->validate(['confirmed' => ['required', 'boolean']]);
        abort_unless(
            $paymentLine->payment()->where('status', 'posted')->exists(),
            409,
            'Only posted payment lines can be confirmed.'
        );

        $paymentLine->forceFill([
            'confirmed_at' => $data['confirmed'] ? now() : null,
            'confirmed_by' => $data['confirmed'] ? $request->user()->id : null,
        ])->save();

        return response()->json($paymentLine->load('confirmedBy'));
    }
}
