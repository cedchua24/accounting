<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInterbranchOrderRequest;
use App\Models\InterbranchOrder;
use App\Services\InterbranchPostingService;
use Illuminate\Http\Request;

class InterbranchOrderController extends Controller
{
    public function __construct(private InterbranchPostingService $postingService) {}

    public function index(Request $request)
    {
        return InterbranchOrder::with('sourceBranch', 'destinationBranch')
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->when($request->branch_id, fn ($query, $branch) => $query->where(fn ($q) => $q->where('source_branch_id', $branch)->orWhere('destination_branch_id', $branch)))
            ->latest('order_date')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreInterbranchOrderRequest $request)
    {
        $order = InterbranchOrder::create($request->validated() + ['created_by' => $request->user()->id]);

        return response()->json($order->load('sourceBranch', 'destinationBranch'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(InterbranchOrder $interbranchOrder)
    {
        return $interbranchOrder->load('sourceBranch', 'destinationBranch', 'journalEntries.lines.chartAccount', 'journalEntries.lines.branch');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreInterbranchOrderRequest $request, InterbranchOrder $interbranchOrder)
    {
        abort_unless($interbranchOrder->status === 'draft', 409, 'Only draft interbranch orders can be edited.');
        $interbranchOrder->update($request->validated());

        return $interbranchOrder->fresh(['sourceBranch', 'destinationBranch']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function post(Request $request, InterbranchOrder $interbranchOrder)
    {
        return $this->postingService->post($interbranchOrder, $request->user()->id);
    }

    public function void(Request $request, InterbranchOrder $interbranchOrder)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->postingService->void($interbranchOrder, $data['reason'], $request->user()->id);
    }

    public function destroy(InterbranchOrder $interbranchOrder)
    {
        abort_unless($interbranchOrder->status === 'draft', 409, 'Only draft interbranch orders can be deleted.');
        $interbranchOrder->delete();

        return response()->noContent();
    }
}
