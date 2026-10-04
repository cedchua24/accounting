<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentObligation extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'branch_id', 'type', 'financial_account_id', 'counterparty_id', 'offset_account_id',
        'statement_date', 'due_date', 'currency', 'principal_amount', 'interest_amount',
        'fee_amount', 'adjustment_amount', 'minimum_due', 'status', 'source_reference', 'legacy_source', 'legacy_id', 'notes',
    ];

    protected $casts = [
        'statement_date' => 'date', 'due_date' => 'date', 'principal_amount' => 'decimal:4',
        'interest_amount' => 'decimal:4', 'fee_amount' => 'decimal:4', 'adjustment_amount' => 'decimal:4', 'minimum_due' => 'decimal:4',
    ];

    protected $appends = ['total_amount', 'paid_amount', 'balance_due'];

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function counterparty()
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function offsetAccount()
    {
        return $this->belongsTo(ChartAccount::class, 'offset_account_id');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function charges()
    {
        return $this->hasMany(ObligationCharge::class);
    }

    public function getTotalAmountAttribute(): string
    {
        return number_format((float) $this->principal_amount + (float) $this->interest_amount + (float) $this->fee_amount + (float) $this->adjustment_amount, 4, '.', '');
    }

    public function balanceAdjustments()
    {
        return $this->hasMany(FinancialAccountAdjustment::class);
    }

    public function getPaidAmountAttribute(): string
    {
        $paid = $this->allocations()->whereHas('payment', fn ($query) => $query->where('status', 'posted'))->sum('amount');

        return number_format((float) $paid, 4, '.', '');
    }

    public function getBalanceDueAttribute(): string
    {
        return number_format(max(0, (float) $this->total_amount - (float) $this->paid_amount), 4, '.', '');
    }
}
