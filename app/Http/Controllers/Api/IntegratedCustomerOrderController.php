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

    public function summaries(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'external_order_ids' => ['required', 'array', 'max:500'],
            'external_order_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);

        return response()->json([
            'data' => $this->orders->findMany($data['source'], $data['external_order_ids']),
        ]);
    }

    public function refundCandidates(Request $request)
    {
        $data = $request->validate([
            'source' => ['sometimes', 'string', 'max:50'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'orders' => ['sometimes', 'array', 'max:500'],
            'orders.*.external_order_id' => ['required', 'integer', 'min:1', 'distinct'],
            'orders.*.order_date' => ['required', 'date_format:Y-m-d'],
            'orders.*.order_total' => ['required', 'numeric', 'min:0'],
        ]);

        $source = $data['source'] ?? 'bakingstore-branch-1';
        $candidates = array_key_exists('orders', $data)
            ? $this->orders->refundCandidates($source, $data['orders'])
            : $this->orders->refundCandidatesFromLegacy(
                $source,
                $data['date_from'] ?? null,
                $data['date_to'] ?? null,
            );

        return response()->json([
            'data' => $candidates,
            'total_count' => count($candidates),
            'code' => 200,
            'message' => 'Successfully fetched online shop order transactions for refund',
        ]);
    }

    public function store(StoreIntegratedCustomerPaymentRequest $request)
    {
        return response()->json($this->orders->recordPayment($request->validated(), $request->user()->id), 201);
    }

    public function paymentReport(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return response()->json($this->orders->paymentReport(
            $data['source'],
            $data['date_from'],
            $data['date_to'],
        ));
    }
}
