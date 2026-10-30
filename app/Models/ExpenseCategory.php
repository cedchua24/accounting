<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_type_id', 'chart_account_id', 'code', 'name', 'description',
        'display_order', 'is_active', 'legacy_source', 'legacy_id',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function type()
    {
        return $this->belongsTo(ExpenseType::class, 'expense_type_id');
    }

    public function expenseType()
    {
        return $this->type();
    }

    public function chartAccount()
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function items()
    {
        return $this->hasMany(ExpenseItem::class)->orderBy('display_order')->orderBy('name');
    }
}
