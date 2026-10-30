<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanPaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_payment_id', 'loan_installment_id', 'principal_amount', 'interest_amount',
        'overdue_interest_amount', 'overdue_interest_waived_amount',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:4',
        'interest_amount' => 'decimal:4',
        'overdue_interest_amount' => 'decimal:4',
        'overdue_interest_waived_amount' => 'decimal:4',
    ];

    public function payment()
    {
        return $this->belongsTo(LoanPayment::class, 'loan_payment_id');
    }

    public function installment()
    {
        return $this->belongsTo(LoanInstallment::class, 'loan_installment_id');
    }
}
