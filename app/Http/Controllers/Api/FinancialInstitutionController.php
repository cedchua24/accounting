<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialInstitutionRequest;
use App\Models\FinancialInstitution;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialInstitutionController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);

        return $this->query($filters)->get();
    }

    public function list(Request $request)
    {
        $filters = $this->filters($request);

        return $this->query($filters)->get();
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

    private function filters(Request $request): array
    {
        return $request->validate([
            'type' => ['nullable', Rule::in(['bank', 'e_wallet', 'cash', 'other'])],
            'active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function query(array $filters)
    {
        return FinancialInstitution::query()
            ->withCount('accounts')
            ->when($filters['type'] ?? null, fn ($query, $value) => $query->where('type', $value))
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $filters['active']))
            ->when($filters['search'] ?? null, fn ($query, $value) => $query->where(function ($search) use ($value) {
                $search->where('name', 'like', "%{$value}%")
                    ->orWhere('code', 'like', "%{$value}%");
            }))
            ->orderBy('name');
    }
}
