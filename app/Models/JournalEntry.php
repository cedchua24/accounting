<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = ['payment_id', 'loan_payment_id', 'interbranch_order_id', 'end_of_day_settlement_id', 'financial_account_transfer_id', 'financial_account_adjustment_id', 'customer_refund_id', 'kind', 'number', 'entry_date', 'memo', 'created_by'];

    protected $casts = ['entry_date' => 'date'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function loanPayment()
    {
        return $this->belongsTo(LoanPayment::class);
    }

    public function interbranchOrder()
    {
        return $this->belongsTo(InterbranchOrder::class);
    }

    public function endOfDaySettlement()
    {
        return $this->belongsTo(EndOfDaySettlement::class);
    }

    public function financialAccountTransfer()
    {
        return $this->belongsTo(FinancialAccountTransfer::class);
    }

    public function financialAccountAdjustment()
    {
        return $this->belongsTo(FinancialAccountAdjustment::class);
    }

    public function customerRefund()
    {
        return $this->belongsTo(CustomerRefund::class);
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }
}
