<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesInvoiceRequest;
use App\Models\SalesInvoice;
use Illuminate\Http\Request;

class SalesInvoiceController extends Controller
{
    public function index(Request $request)
    {
        return SalesInvoice::with('branch', 'customer')
            ->when($request->branch_id, fn ($query, $branch) => $query->where('branch_id', $branch))
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->latest('invoice_date')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSalesInvoiceRequest $request)
    {
        return response()->json(SalesInvoice::create($request->validated())->load('branch', 'customer'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(SalesInvoice $salesInvoice)
    {
        return $salesInvoice->load('branch', 'customer', 'payments.lines.financialAccount', 'payments.lines.paymentMethod');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreSalesInvoiceRequest $request, SalesInvoice $salesInvoice)
    {
        abort_if($salesInvoice->payments()->where('status', 'posted')->exists(), 409, 'An invoice with posted payments cannot be edited.');
        $salesInvoice->update($request->validated());

        return $salesInvoice->fresh(['branch', 'customer']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SalesInvoice $salesInvoice)
    {
        abort_if($salesInvoice->payments()->exists(), 409, 'An invoice with payments cannot be deleted.');
        $salesInvoice->delete();

        return response()->noContent();
    }
}
