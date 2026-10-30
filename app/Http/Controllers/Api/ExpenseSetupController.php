<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseType;
use Illuminate\Http\Request;

class ExpenseSetupController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'include_hidden' => ['nullable', 'boolean'],
            'expense_type_id' => ['nullable', 'integer', 'exists:expense_types,id'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $includeHidden = (bool) ($filters['include_hidden'] ?? false);
        $search = $filters['search'] ?? null;

        $types = ExpenseType::query()
            ->where('is_active', true)
            ->when($filters['expense_type_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->with('chartAccount')
            ->with(['categories' => function ($categories) use ($includeHidden, $search) {
                $categories->where('is_active', true)
                    ->with('chartAccount')
                    ->with(['items' => function ($items) use ($includeHidden, $search) {
                        $items->where('is_active', true)
                            ->when(! $includeHidden, fn ($query) => $query->where('is_hidden', false))
                            ->when($search, fn ($query, $value) => $query->where(
                                fn ($match) => $match->where('code', 'like', "%{$value}%")
                                    ->orWhere('name', 'like', "%{$value}%")
                                    ->orWhere('details', 'like', "%{$value}%")
                            ))
                            ->with('chartAccount')
                            ->orderBy('display_order')->orderBy('code');
                    }])
                    ->when($search, fn ($query) => $query->where(
                        fn ($match) => $match->where('name', 'like', "%{$search}%")
                            ->orWhereHas('items', fn ($items) => $items
                                ->where('is_active', true)
                                ->when(! $includeHidden, fn ($query) => $query->where('is_hidden', false))
                                ->where(fn ($item) => $item->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%")
                                    ->orWhere('details', 'like', "%{$search}%")))
                    ))
                    ->orderBy('display_order')->orderBy('code');
            }])
            ->when($search, fn ($query) => $query->where(
                fn ($match) => $match->where('name', 'like', "%{$search}%")
                    ->orWhereHas('categories', fn ($categories) => $categories
                        ->where('is_active', true)
                        ->where(fn ($category) => $category->where('name', 'like', "%{$search}%")
                            ->orWhereHas('items', fn ($items) => $items
                                ->where('is_active', true)
                                ->when(! $includeHidden, fn ($item) => $item->where('is_hidden', false))
                                ->where(fn ($item) => $item->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%")
                                    ->orWhere('details', 'like', "%{$search}%")))))
            ))
            ->orderBy('display_order')->orderBy('code')
            ->get();

        return response()->json([
            'data' => $types->map(fn ($type) => [
                ...$type->toArray(),
                'expense_type_code' => $type->code,
                'expense_type' => $type->name,
                'status' => 0,
                'categories' => $type->categories->map(fn ($category) => [
                    ...$category->toArray(),
                    'expense_category_code' => $category->code,
                    'expense_category_name' => $category->name,
                    'status' => 0,
                    'items' => $category->items->map(fn ($item) => [
                        ...$item->toArray(),
                        'expense_code' => $item->code,
                        'expense_name' => $item->name,
                        'status' => 0,
                    ])->values(),
                ])->filter(fn ($category) => ! $search || $category['items']->isNotEmpty()
                    || stripos($category['name'], $search) !== false)->values(),
            ])->values(),
        ]);
    }
}
