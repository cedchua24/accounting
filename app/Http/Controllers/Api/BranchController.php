<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBranchRequest;
use App\Models\Branch;

class BranchController extends Controller
{
    public function index()
    {
        return Branch::with($this->relations())->orderBy('name')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBranchRequest $request)
    {
        return response()->json(Branch::create($request->validated())->load($this->relations()), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Branch $branch)
    {
        return $branch->load($this->relations());
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreBranchRequest $request, Branch $branch)
    {
        $branch->update($request->validated());

        return $branch->fresh($this->relations());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Branch $branch)
    {
        $branch->delete();

        return response()->noContent();
    }

    private function relations(): array
    {
        return ['inventoryAccount', 'dueFromAccount', 'dueToAccount'];
    }
}
