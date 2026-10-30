<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    public const CREDIT_CARD_PAYMENT_TYPES = ['cash', 'bank_transfer', 'e_wallet'];
    public const LOAN_PAYMENT_TYPES = ['cash', 'bank_transfer', 'cheque', 'e_wallet'];

    protected $fillable = ['code', 'name', 'type', 'creates_obligation', 'is_active'];

    protected $casts = ['creates_obligation' => 'boolean', 'is_active' => 'boolean'];

    public function scopeForCreditCardPayments($query)
    {
        return $query
            ->where('is_active', true)
            ->where('creates_obligation', false)
            ->whereIn('type', self::CREDIT_CARD_PAYMENT_TYPES);
    }

    public function scopeForLoanPayments($query)
    {
        return $query
            ->where('is_active', true)
            ->whereIn('type', self::LOAN_PAYMENT_TYPES);
    }

    public function compatibleFundingAccountKinds(): array
    {
        return match ($this->type) {
            'cash' => ['cash'],
            'bank_transfer' => ['bank_account', 'checking_account'],
            'cheque' => ['checking_account'],
            'e_wallet' => ['e_wallet'],
            default => [],
        };
    }
}
