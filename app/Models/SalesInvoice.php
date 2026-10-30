<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesInvoice extends Model
{
    use HasFactory;

    protected $fillable = ['branch_id', 'customer_id', 'number', 'invoice_date', 'currency', 'total_amount', 'status', 'legacy_source', 'legacy_id', 'description'];

    protected $casts = ['invoice_date' => 'date', 'total_amount' => 'decimal:4'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Counterparty::class, 'customer_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds()
    {
        return $this->hasMany(CustomerRefund::class);
    }
}
