<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIntegratedCustomerPaymentRequest;
use App\Services\IntegratedCustomerOrderService;
use Illuminate\Http\Request;

class IntegratedCustomerOrderController extends Controller
{
    public function __construct(private IntegratedCustomerOrderService $orders) {}

    public function show(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'external_order_id' => ['required', 'integer', 'min:1'],
        ]);
        $summary = $this->orders->find($data['source'], (int) $data['external_order_id']);

        return $summary ? response()->json($summary) : response()->json(['data' => [], 'total_payment' => '0.0000', 'balance' => null]);
    }

    public function store(StoreIntegratedCustomerPaymentRequest $request)
    {
        return response()->json($this->orders->recordPayment($request->validated(), $request->user()->id), 201);
    }
}
