<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_id', 'payment_method_id', 'financial_account_id', 'excess_treatment', 'overpayment_amount',
        'payment_date', 'amount', 'reference', 'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:4',
        'overpayment_amount' => 'decimal:4',
    ];

    protected $appends = ['overdue_interest_amount', 'overdue_interest_classification'];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function allocations()
    {
        return $this->hasMany(LoanPaymentAllocation::class);
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function getOverdueInterestAmountAttribute(): string
    {
        $amount = $this->relationLoaded('allocations')
            ? $this->allocations->sum('overdue_interest_amount')
            : $this->allocations()->sum('overdue_interest_amount');

        return number_format((float) $amount, 4, '.', '');
    }

    public function getOverdueInterestClassificationAttribute(): ?string
    {
        if (bccomp($this->overdue_interest_amount, '0.0000', 4) !== 1) {
            return null;
        }

        $loan = $this->relationLoaded('loan') ? $this->loan : $this->loan()->first();

        return $loan?->direction === 'borrowed' ? 'expense' : 'income';
    }
}
