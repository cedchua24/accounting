<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartAccountGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'normal_balance', 'display_order', 'is_active',
        'legacy_source', 'legacy_id',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function chartAccounts()
    {
        return $this->hasMany(ChartAccount::class, 'group_id');
    }

    public function accounts()
    {
        return $this->chartAccounts();
    }

    public function expenseTypes()
    {
        return $this->hasMany(ExpenseType::class);
    }
}
