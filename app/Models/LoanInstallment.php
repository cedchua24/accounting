<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanInstallment extends Model
{
    use HasFactory;

    protected $fillable = ['loan_id', 'installment_number', 'due_date', 'principal_due', 'interest_due'];

    protected $casts = [
        'due_date' => 'date',
        'installment_number' => 'integer',
        'principal_due' => 'decimal:4',
        'interest_due' => 'decimal:4',
    ];

    protected $appends = [
        'scheduled_amount', 'principal_paid', 'interest_paid',
        'overdue_interest_paid', 'overdue_interest_waived',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function allocations()
    {
        return $this->hasMany(LoanPaymentAllocation::class);
    }

    public function adjustments()
    {
        return $this->hasMany(LoanInstallmentAdjustment::class);
    }

    public function getScheduledAmountAttribute(): string
    {
        return bcadd((string) $this->principal_due, (string) $this->interest_due, 4);
    }

    public function getPrincipalPaidAttribute(): string
    {
        $paid = $this->relationLoaded('allocations')
            ? $this->allocations->sum('principal_amount')
            : $this->allocations()->sum('principal_amount');

        return number_format((float) $paid, 4, '.', '');
    }

    public function getInterestPaidAttribute(): string
    {
        $paid = $this->relationLoaded('allocations')
            ? $this->allocations->sum('interest_amount')
            : $this->allocations()->sum('interest_amount');

        return number_format((float) $paid, 4, '.', '');
    }

    public function getOverdueInterestPaidAttribute(): string
    {
        $paid = $this->relationLoaded('allocations')
            ? $this->allocations->sum('overdue_interest_amount')
            : $this->allocations()->sum('overdue_interest_amount');

        return number_format((float) $paid, 4, '.', '');
    }

    public function getOverdueInterestWaivedAttribute(): string
    {
        $waived = $this->relationLoaded('allocations')
            ? $this->allocations->sum('overdue_interest_waived_amount')
            : $this->allocations()->sum('overdue_interest_waived_amount');

        return number_format((float) $waived, 4, '.', '');
    }
}
