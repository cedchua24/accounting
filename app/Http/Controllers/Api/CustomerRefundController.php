<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRefundRequest;
use App\Models\CustomerRefund;
use App\Services\CustomerRefundService;
use Illuminate\Http\Request;

class CustomerRefundController extends Controller
{
    public function __construct(private CustomerRefundService $refunds) {}

    public function index(Request $request)
    {
        return CustomerRefund::with('originalPayment', 'salesInvoice', 'branch', 'customer', 'financialAccount.institution', 'paymentMethod')
            ->when($request->sales_invoice_id, fn ($query, $id) => $query->where('sales_invoice_id', $id))
            ->when($request->original_payment_id, fn ($query, $id) => $query->where('original_payment_id', $id))
            ->when($request->branch_id, fn ($query, $id) => $query->where('branch_id', $id))
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->latest('refund_date')->latest('id')->paginate();
    }

    public function store(StoreCustomerRefundRequest $request)
    {
        return response()->json($this->refunds->create($request->validated(), $request->user()->id), 201);
    }

    public function show(CustomerRefund $customerRefund)
    {
        return $customerRefund->load($this->relations());
    }

    public function post(Request $request, CustomerRefund $customerRefund)
    {
        return $this->refunds->post($customerRefund, $request->user()->id);
    }

    public function void(Request $request, CustomerRefund $customerRefund)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        return $this->refunds->void($customerRefund, $data['reason'], $request->user()->id);
    }

    public function destroy(CustomerRefund $customerRefund)
    {
        abort_unless($customerRefund->status === 'draft', 409, 'Only draft refunds can be deleted.');
        $customerRefund->delete();
        return response()->noContent();
    }

    private function relations(): array
    {
        return [
            'originalPayment', 'salesInvoice', 'branch', 'customer',
            'financialAccount.institution', 'financialAccount.chartAccount',
            'paymentMethod', 'refundAccount', 'journalEntries.lines.chartAccount',
        ];
    }
}
