<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = ['branch_id', 'number', 'supplier_id', 'order_date', 'currency', 'total_amount', 'status', 'description', 'legacy_source', 'legacy_id'];

    protected $casts = ['order_date' => 'date', 'total_amount' => 'decimal:4'];

    public function supplier()
    {
        return $this->belongsTo(Counterparty::class, 'supplier_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
