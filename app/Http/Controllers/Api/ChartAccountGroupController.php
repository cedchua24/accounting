<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChartAccountGroupRequest;
use App\Models\ChartAccountGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ChartAccountGroupController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $groups = $this->query($filters)->paginate($filters['per_page'] ?? 25);
        $groups->through(fn (ChartAccountGroup $group) => $this->data($group));

        return $groups;
    }

    public function list(Request $request)
    {
        $filters = $this->filters($request, false);

        return response()->json($this->query($filters)->get()
            ->map(fn (ChartAccountGroup $group) => $this->data($group)));
    }

    public function store(StoreChartAccountGroupRequest $request)
    {
        $group = ChartAccountGroup::create($request->validated());

        return response()->json($this->data($group->loadCount('accounts')), 201);
    }

    public function show(ChartAccountGroup $chartAccountGroup)
    {
        return $this->data($chartAccountGroup->loadCount('accounts'));
    }

    public function update(StoreChartAccountGroupRequest $request, ChartAccountGroup $chartAccountGroup)
    {
        $chartAccountGroup->update($request->validated());

        return $this->data($chartAccountGroup->fresh()->loadCount('accounts'));
    }

    public function destroy(ChartAccountGroup $chartAccountGroup)
    {
        if ($chartAccountGroup->accounts()->exists()) {
            return response()->json([
                'message' => 'This chart-account group is already in use. Deactivate it instead of deleting it.',
            ], 409);
        }
        $chartAccountGroup->delete();

        return response()->noContent();
    }

    private function filters(Request $request, bool $paginated = true): array
    {
        return $request->validate([
            'active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => $paginated ? ['nullable', 'integer', 'min:1', 'max:100'] : ['prohibited'],
        ]);
    }

    private function query(array $filters): Builder
    {
        return ChartAccountGroup::query()
            ->withCount('accounts')
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $filters['active']))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($match) => $match->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")
            ))
            ->orderBy('display_order')->orderBy('code');
    }

    private function data(ChartAccountGroup $group): array
    {
        return [
            ...$group->toArray(),
            'chart_of_account_code' => $group->code,
            'chart_of_account_name' => $group->name,
            'status' => $group->is_active ? 0 : 1,
        ];
    }
}
