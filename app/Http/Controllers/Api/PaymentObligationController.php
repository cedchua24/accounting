<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentObligationRequest;
use App\Models\PaymentObligation;
use Illuminate\Http\Request;

class PaymentObligationController extends Controller
{
    public function index(Request $request)
    {
        return PaymentObligation::with('branch', 'financialAccount.institution', 'counterparty', 'offsetAccount')
            ->when($request->branch_id, fn ($query, $branch) => $query->where(fn ($scope) => $scope->whereNull('branch_id')->orWhere('branch_id', $branch)))
            ->when($request->type, fn ($query, $type) => $query->where('type', $type))
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->when($request->due_before, fn ($query, $date) => $query->whereDate('due_date', '<=', $date))
            ->orderBy('due_date')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePaymentObligationRequest $request)
    {
        return response()->json(PaymentObligation::create($request->validated())->load('branch', 'financialAccount.institution', 'counterparty', 'offsetAccount'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(PaymentObligation $paymentObligation)
    {
        return $paymentObligation->load('branch', 'financialAccount.institution', 'counterparty', 'offsetAccount', 'allocations.payment');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StorePaymentObligationRequest $request, PaymentObligation $paymentObligation)
    {
        abort_if($paymentObligation->allocations()->whereHas('payment', fn ($query) => $query->where('status', 'posted'))->exists(), 409, 'An obligation with posted payments cannot be edited.');
        $paymentObligation->update($request->validated());

        return $paymentObligation->fresh(['branch', 'financialAccount.institution', 'counterparty', 'offsetAccount']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PaymentObligation $paymentObligation)
    {
        abort_if($paymentObligation->allocations()->exists(), 409, 'An allocated obligation cannot be deleted.');
        $paymentObligation->delete();

        return response()->noContent();
    }
}
