<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'number', 'payee_id', 'chart_account_id', 'expense_item_id',
        'expense_date', 'currency', 'amount', 'status', 'description', 'reference',
        'approval_status', 'approval_notes', 'created_by', 'approved_by', 'approved_at',
        'is_received', 'received_date', 'source_type', 'source_id', 'system_generated',
        'legacy_source', 'legacy_id',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'received_date' => 'date',
        'approved_at' => 'datetime',
        'amount' => 'decimal:4',
        'is_received' => 'boolean',
        'system_generated' => 'boolean',
    ];

    public function payee()
    {
        return $this->belongsTo(Counterparty::class, 'payee_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function chartAccount()
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function expenseItem()
    {
        return $this->belongsTo(ExpenseItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
