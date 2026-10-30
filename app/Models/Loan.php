<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'direction', 'borrower_type', 'financial_institution_id', 'user_account_id',
        'counterparty_id', 'party_name', 'financial_account_id',
        'start_date', 'first_due_date', 'currency', 'principal_amount', 'total_interest_amount',
        'term_months', 'overdue_interest_rate', 'overdue_interest_period', 'status', 'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'first_due_date' => 'date',
        'principal_amount' => 'decimal:4',
        'total_interest_amount' => 'decimal:4',
        'overdue_interest_rate' => 'decimal:4',
        'term_months' => 'integer',
    ];

    protected $appends = ['total_amount', 'borrower_name'];

    public function counterparty()
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function financialInstitution()
    {
        return $this->belongsTo(FinancialInstitution::class);
    }

    public function userAccount()
    {
        return $this->belongsTo(UserAccount::class);
    }

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function installments()
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('installment_number');
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class)->orderBy('payment_date')->orderBy('id');
    }

    public function installmentAdjustments()
    {
        return $this->hasMany(LoanInstallmentAdjustment::class);
    }

    public function getTotalAmountAttribute(): string
    {
        return bcadd((string) $this->principal_amount, (string) $this->total_interest_amount, 4);
    }

    public function getBorrowerNameAttribute(): ?string
    {
        if ($this->borrower_type === 'bank') {
            $institution = $this->relationLoaded('financialInstitution')
                ? $this->financialInstitution
                : $this->financialInstitution()->first();

            return $institution?->name;
        }

        if ($this->borrower_type === 'credit_card') {
            $account = $this->relationLoaded('financialAccount')
                ? $this->financialAccount
                : $this->financialAccount()->with('institution')->first();

            return $account?->name;
        }

        $counterparty = $this->relationLoaded('counterparty')
            ? $this->counterparty
            : $this->counterparty()->first();

        return $counterparty?->name ?? $this->party_name;
    }
}
