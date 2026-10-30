<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanInstallmentAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_id', 'loan_installment_id', 'adjusted_by',
        'old_due_date', 'new_due_date',
        'old_principal_due', 'new_principal_due',
        'old_interest_due', 'new_interest_due',
        'reason',
    ];

    protected $casts = [
        'old_due_date' => 'date',
        'new_due_date' => 'date',
        'old_principal_due' => 'decimal:4',
        'new_principal_due' => 'decimal:4',
        'old_interest_due' => 'decimal:4',
        'new_interest_due' => 'decimal:4',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function installment()
    {
        return $this->belongsTo(LoanInstallment::class, 'loan_installment_id');
    }

    public function adjustedByUser()
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
