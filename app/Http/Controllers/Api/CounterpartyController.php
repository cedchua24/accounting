<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCounterpartyRequest;
use App\Models\Counterparty;
use Illuminate\Http\Request;

class CounterpartyController extends Controller
{
    public function index(Request $request)
    {
        return Counterparty::when($request->type, fn ($q, $type) => $q->where('type', $type))->orderBy('name')->paginate();
    }

    public function store(StoreCounterpartyRequest $request)
    {
        return response()->json(Counterparty::create($request->validated()), 201);
    }

    public function show(Counterparty $counterparty)
    {
        return $counterparty;
    }

    public function update(StoreCounterpartyRequest $request, Counterparty $counterparty)
    {
        $counterparty->update($request->validated());

        return $counterparty->fresh();
    }

    public function destroy(Counterparty $counterparty)
    {
        $counterparty->delete();

        return response()->noContent();
    }
}
