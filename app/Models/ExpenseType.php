<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseType extends Model
{
    use HasFactory;

    protected $fillable = [
        'chart_account_group_id', 'chart_account_id', 'code', 'name', 'is_profit', 'display_order',
        'is_active', 'legacy_source', 'legacy_id',
    ];

    protected $casts = [
        'is_profit' => 'boolean',
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function chartAccountGroup()
    {
        return $this->belongsTo(ChartAccountGroup::class);
    }

    public function chartAccount()
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function categories()
    {
        return $this->hasMany(ExpenseCategory::class)->orderBy('display_order')->orderBy('name');
    }
}
