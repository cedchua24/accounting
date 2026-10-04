<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = ['number', 'branch_id', 'type', 'direction', 'counterparty_id', 'sales_invoice_id', 'purchase_order_id', 'expense_id', 'offset_account_id', 'payment_date', 'currency', 'amount', 'idempotency_key', 'status', 'memo', 'created_by'];

    protected $attributes = ['status' => 'draft'];

    protected $casts = ['payment_date' => 'date', 'amount' => 'decimal:4', 'posted_at' => 'datetime', 'voided_at' => 'datetime'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines()
    {
        return $this->hasMany(PaymentLine::class);
    }

    public function counterparty()
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function offsetAccount()
    {
        return $this->belongsTo(ChartAccount::class, 'offset_account_id');
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
