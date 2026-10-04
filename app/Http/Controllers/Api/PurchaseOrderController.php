<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        return PurchaseOrder::with('branch', 'supplier')
            ->when($request->branch_id, fn ($query, $branch) => $query->where('branch_id', $branch))
            ->latest('order_date')->paginate();
    }

    public function store(StorePurchaseOrderRequest $request)
    {
        return response()->json(PurchaseOrder::create($request->validated())->load('branch', 'supplier'), 201);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return $purchaseOrder->load('branch', 'supplier', 'payments');
    }

    public function update(StorePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->update($request->validated());

        return $purchaseOrder->fresh(['branch', 'supplier']);
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->delete();

        return response()->noContent();
    }
}
