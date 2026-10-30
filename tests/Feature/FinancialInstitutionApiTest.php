<?php

namespace Tests\Feature;

use App\Models\FinancialInstitution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialInstitutionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dropdown_list_returns_active_banks_as_a_flat_array(): void
    {
        Sanctum::actingAs(User::factory()->create());
        FinancialInstitution::create(['code' => 'BDO', 'name' => 'BDO', 'type' => 'bank', 'is_active' => true]);
        FinancialInstitution::create(['code' => 'BPI', 'name' => 'BPI', 'type' => 'bank', 'is_active' => false]);
        FinancialInstitution::create(['code' => 'GCASH', 'name' => 'GCash', 'type' => 'e_wallet', 'is_active' => true]);

        $this->getJson('/api/financial-institutions/list?type=bank&active=1')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.code', 'BDO')
            ->assertJsonPath('0.name', 'BDO')
            ->assertJsonPath('0.type', 'bank')
            ->assertJsonPath('0.is_active', true);
    }

    public function test_main_non_paginated_list_supports_filters(): void
    {
        Sanctum::actingAs(User::factory()->create());
        FinancialInstitution::create(['code' => 'BDO', 'name' => 'BDO', 'type' => 'bank', 'is_active' => true]);
        FinancialInstitution::create(['code' => 'BPI', 'name' => 'BPI', 'type' => 'bank', 'is_active' => true]);

        $this->getJson('/api/financial-institutions?type=bank&active=1&search=BDO')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.code', 'BDO')
            ->assertJsonMissingPath('current_page')
            ->assertJsonMissingPath('links');
    }
}
