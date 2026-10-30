<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id', 'code', 'name', 'type', 'parent_id', 'description',
        'is_postable', 'display_order', 'is_active', 'legacy_source', 'legacy_id',
    ];

    protected $casts = [
        'is_postable' => 'boolean',
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function group()
    {
        return $this->belongsTo(ChartAccountGroup::class, 'group_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function expenseTypes()
    {
        return $this->hasMany(ExpenseType::class);
    }

    public function expenseCategories()
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function expenseItems()
    {
        return $this->hasMany(ExpenseItem::class);
    }
}
