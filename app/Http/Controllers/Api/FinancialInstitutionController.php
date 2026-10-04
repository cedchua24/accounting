<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialInstitutionRequest;
use App\Models\FinancialInstitution;

class FinancialInstitutionController extends Controller
{
    public function index()
    {
        return FinancialInstitution::orderBy('name')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFinancialInstitutionRequest $request)
    {
        return response()->json(FinancialInstitution::create($request->validated()), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(FinancialInstitution $financialInstitution)
    {
        return $financialInstitution->load('accounts');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreFinancialInstitutionRequest $request, FinancialInstitution $financialInstitution)
    {
        $financialInstitution->update($request->validated());

        return $financialInstitution->fresh();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FinancialInstitution $financialInstitution)
    {
        $financialInstitution->delete();

        return response()->noContent();
    }
}
