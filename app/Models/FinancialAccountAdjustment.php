<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialAccountAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'financial_account_id', 'payment_obligation_id', 'adjustment_account_id', 'adjustment_date',
        'currency', 'system_balance_before', 'actual_balance', 'adjustment_amount',
        'expected_amount_due_before', 'paid_amount_before', 'actual_amount_due',
        'reference', 'reason', 'status', 'created_by', 'posted_by', 'posted_at',
        'voided_by', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'adjustment_date' => 'date', 'system_balance_before' => 'decimal:4',
        'actual_balance' => 'decimal:4', 'adjustment_amount' => 'decimal:4',
        'expected_amount_due_before' => 'decimal:4', 'paid_amount_before' => 'decimal:4',
        'actual_amount_due' => 'decimal:4',
        'posted_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function adjustmentAccount()
    {
        return $this->belongsTo(ChartAccount::class, 'adjustment_account_id');
    }

    public function paymentObligation()
    {
        return $this->belongsTo(PaymentObligation::class);
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
