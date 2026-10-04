<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = ['payment_obligation_id', 'amount'];

    protected $casts = ['amount' => 'decimal:4'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function obligation()
    {
        return $this->belongsTo(PaymentObligation::class, 'payment_obligation_id');
    }
}
