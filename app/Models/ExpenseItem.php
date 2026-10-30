<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_category_id', 'chart_account_id', 'code', 'name', 'details',
        'is_hidden', 'is_active', 'system_key', 'display_order', 'legacy_source', 'legacy_id',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function chartAccount()
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
