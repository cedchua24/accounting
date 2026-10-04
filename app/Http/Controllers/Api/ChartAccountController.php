<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChartAccountRequest;
use App\Models\ChartAccount;

class ChartAccountController extends Controller
{
    public function index()
    {
        return ChartAccount::with('parent')->orderBy('code')->paginate();
    }

    public function store(StoreChartAccountRequest $request)
    {
        return response()->json(ChartAccount::create($request->validated()), 201);
    }

    public function show(ChartAccount $chartAccount)
    {
        return $chartAccount->load('parent', 'children');
    }

    public function update(StoreChartAccountRequest $request, ChartAccount $chartAccount)
    {
        $chartAccount->update($request->validated());

        return $chartAccount->fresh();
    }

    public function destroy(ChartAccount $chartAccount)
    {
        $chartAccount->delete();

        return response()->noContent();
    }
}
