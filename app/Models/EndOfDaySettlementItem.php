<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EndOfDaySettlementItem extends Model
{
    use HasFactory;

    protected $fillable = ['branch_id', 'payment_line_id', 'source_financial_account_id', 'amount', 'transfer_amount'];

    protected $casts = ['amount' => 'decimal:4', 'transfer_amount' => 'decimal:4'];

    public function settlement()
    {
        return $this->belongsTo(EndOfDaySettlement::class, 'end_of_day_settlement_id');
    }

    public function paymentLine()
    {
        return $this->belongsTo(PaymentLine::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function sourceAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'source_financial_account_id');
    }
}
