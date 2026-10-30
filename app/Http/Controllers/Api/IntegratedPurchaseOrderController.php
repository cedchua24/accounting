<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIntegratedPurchaseOrderPaymentRequest;
use App\Services\IntegratedPurchaseOrderService;
use Illuminate\Http\Request;

class IntegratedPurchaseOrderController extends Controller
{
    public function __construct(private IntegratedPurchaseOrderService $orders) {}

    public function show(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'external_order_id' => ['required', 'integer', 'min:1'],
        ]);
        $summary = $this->orders->find($data['source'], (int) $data['external_order_id']);

        return $summary
            ? response()->json($summary)
            : response()->json(['data' => [], 'total_payment' => '0.0000', 'balance' => null]);
    }

    public function summaries(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'external_order_ids' => ['required', 'array', 'max:500'],
            'external_order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return response()->json([
            'data' => $this->orders->findMany($data['source'], $data['external_order_ids']),
        ]);
    }

    public function store(StoreIntegratedPurchaseOrderPaymentRequest $request)
    {
        return response()->json($this->orders->recordPayment($request->validated(), $request->user()->id), 201);
    }
}
