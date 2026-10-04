<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ObligationCharge extends Model
{
    use HasFactory;

    protected $fillable = ['payment_obligation_id', 'payment_line_id', 'installment_number', 'amount'];

    protected $casts = ['amount' => 'decimal:4', 'installment_number' => 'integer'];

    public function obligation()
    {
        return $this->belongsTo(PaymentObligation::class, 'payment_obligation_id');
    }

    public function paymentLine()
    {
        return $this->belongsTo(PaymentLine::class);
    }
}
