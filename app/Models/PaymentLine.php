<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentLine extends Model
{
    use HasFactory;

    protected $fillable = ['financial_account_id', 'payment_method_id', 'amount', 'installment_count', 'reference', 'legacy_source', 'legacy_id'];

    protected $casts = ['amount' => 'decimal:4', 'installment_count' => 'integer'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function obligationCharges()
    {
        return $this->hasMany(ObligationCharge::class)->orderBy('installment_number');
    }

    public function settlementItems()
    {
        return $this->hasMany(EndOfDaySettlementItem::class);
    }
}
