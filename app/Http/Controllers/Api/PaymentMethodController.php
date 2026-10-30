<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentMethodRequest;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentMethodController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['cash', 'bank_transfer', 'cheque', 'credit_card', 'e_wallet', 'other'])],
            'active' => ['nullable', 'boolean'],
            'creates_obligation' => ['nullable', 'boolean'],
        ]);

        return PaymentMethod::query()
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->when(array_key_exists('creates_obligation', $filters), fn ($query) => $query->where('creates_obligation', $request->boolean('creates_obligation')))
            ->orderBy('name')
            ->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePaymentMethodRequest $request)
    {
        return response()->json(PaymentMethod::create($request->validated()), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(PaymentMethod $paymentMethod)
    {
        return $paymentMethod;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StorePaymentMethodRequest $request, PaymentMethod $paymentMethod)
    {
        $paymentMethod->update($request->validated());

        return $paymentMethod->fresh();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PaymentMethod $paymentMethod)
    {
        $paymentMethod->delete();

        return response()->noContent();
    }
}
