<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChartAccountRequest;
use App\Models\ChartAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChartAccountController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);

        return $this->query($filters)
            ->with('group', 'parent')
            ->withCount('children')
            ->orderBy('display_order')->orderBy('code')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function list(Request $request)
    {
        $filters = $this->filters($request);

        return $this->query($filters)
            ->with('group', 'parent')
            ->withCount('children')
            ->orderBy('display_order')->orderBy('code')
            ->get();
    }

    public function tree(Request $request)
    {
        $filters = $this->filters($request);
        $accounts = $this->query($filters)
            ->with('group')
            ->orderBy('display_order')->orderBy('code')
            ->get();
        $byParent = $accounts->groupBy(fn (ChartAccount $account) => (string) ($account->parent_id ?? 0));
        $build = function (int $parentId) use (&$build, $byParent) {
            return $byParent->get((string) $parentId, collect())->map(function (ChartAccount $account) use (&$build) {
                $data = $account->toArray();
                $data['children'] = $build($account->id);

                return $data;
            })->values();
        };

        return $build(0);
    }

    public function store(StoreChartAccountRequest $request)
    {
        return response()->json(ChartAccount::create($request->validated()), 201);
    }

    public function show(ChartAccount $chartAccount)
    {
        return $chartAccount->load('group', 'parent', 'children.group');
    }

    public function update(StoreChartAccountRequest $request, ChartAccount $chartAccount)
    {
        $chartAccount->update($request->validated());

        return $chartAccount->fresh();
    }

    public function destroy(ChartAccount $chartAccount)
    {
        $isReferenced = $chartAccount->children()->exists()
            || $chartAccount->expenseTypes()->exists()
            || $chartAccount->expenseCategories()->exists()
            || $chartAccount->expenseItems()->exists()
            || DB::table('journal_lines')->where('chart_account_id', $chartAccount->id)->exists()
            || DB::table('financial_accounts')->where('chart_account_id', $chartAccount->id)->exists()
            || DB::table('expenses')->where('chart_account_id', $chartAccount->id)->exists();
        abort_if($isReferenced, 409, 'This chart account has accounting history or dependent records. Deactivate it instead.');
        $chartAccount->delete();

        return response()->noContent();
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:chart_account_groups,id'],
            'type' => ['nullable', 'in:asset,liability,equity,revenue,expense'],
            'parent_id' => ['nullable', 'integer', 'exists:chart_accounts,id'],
            'active' => ['nullable', 'boolean'],
            'postable' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function query(array $filters)
    {
        return ChartAccount::query()
            ->when($filters['group_id'] ?? null, fn ($query, $value) => $query->where('group_id', $value))
            ->when($filters['type'] ?? null, fn ($query, $value) => $query->where('type', $value))
            ->when(array_key_exists('parent_id', $filters), fn ($query) => $query->where('parent_id', $filters['parent_id']))
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $filters['active']))
            ->when(array_key_exists('postable', $filters), fn ($query) => $query->where('is_postable', $filters['postable']))
            ->when($filters['search'] ?? null, fn ($query, $value) => $query->where(
                fn ($search) => $search->where('code', 'like', "%{$value}%")
                    ->orWhere('name', 'like', "%{$value}%")
            ));
    }
}
