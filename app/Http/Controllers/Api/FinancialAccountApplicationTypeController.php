<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccountApplicationType;
use Illuminate\Http\Request;

class FinancialAccountApplicationTypeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'active' => ['sometimes', 'boolean'],
        ]);

        return FinancialAccountApplicationType::query()
            ->when(
                array_key_exists('active', $filters),
                fn ($query) => $query->where('is_active', $request->boolean('active')),
                fn ($query) => $query->where('is_active', true),
            )
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }
}
