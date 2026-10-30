<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Payment;
use App\Services\PaymentPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(private PaymentPostingService $postingService) {}

    public function index(Request $request)
    {
        return Payment::with('branch', 'lines.financialAccount.institution', 'lines.financialAccount.ownerBranch', 'lines.paymentMethod', 'refunds', 'counterparty', 'salesInvoice', 'offsetAccount')
            ->when($request->branch_id, fn ($q, $branch) => $q->where('branch_id', $branch))
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->latest('payment_date')->latest('id')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePaymentRequest $request)
    {
        $payment = DB::transaction(function () use ($request) {
            $data = $request->safe()->except(['post', 'allocations', 'lines']);
            $data['number'] = 'PAY-'.Str::upper((string) Str::ulid());
            $data['direction'] = $data['type'] === 'customer' ? 'inflow' : 'outflow';
            $data['currency'] = strtoupper($data['currency']);
            $data['amount'] = collect($request->input('lines'))->sum('amount');
            $data['created_by'] = $request->user()->id;
            if ($data['type'] === 'customer') {
                $data['counterparty_id'] = \App\Models\SalesInvoice::findOrFail($data['sales_invoice_id'])->customer_id;
            } elseif ($data['type'] === 'purchase_order') {
                $data['counterparty_id'] = \App\Models\PurchaseOrder::findOrFail($data['purchase_order_id'])->supplier_id;
            } elseif ($data['type'] === 'expense') {
                $data['counterparty_id'] = \App\Models\Expense::findOrFail($data['expense_id'])->payee_id;
            }
            $payment = Payment::create($data);
            $payment->lines()->createMany($request->input('lines'));
            $payment->allocations()->createMany($request->input('allocations', []));

            return $request->boolean('post')
                ? $this->postingService->post($payment, $request->user()->id)
                : $payment;
        });

        return response()->json($payment->loadMissing($this->relations()), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Payment $payment)
    {
        return $payment->load($this->relations());
    }

    /**
     * Update the specified resource in storage.
     */
    public function post(Request $request, Payment $payment)
    {
        return $this->postingService->post($payment, $request->user()->id);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function void(Request $request, Payment $payment)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->postingService->void($payment, $data['reason'], $request->user()->id);
    }

    public function destroy(Payment $payment)
    {
        abort_unless($payment->status === 'draft', 409, 'Only draft payments can be deleted.');
        $payment->delete();

        return response()->noContent();
    }

    private function relations(): array
    {
        return ['branch', 'lines.financialAccount.institution', 'lines.financialAccount.ownerBranch', 'lines.financialAccount.chartAccount', 'lines.paymentMethod', 'lines.obligationCharges.obligation', 'refunds', 'counterparty', 'salesInvoice', 'purchaseOrder', 'expense', 'offsetAccount', 'allocations.obligation', 'journalEntries.lines.chartAccount'];
    }
}
