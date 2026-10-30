<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'original_payment_id', 'sales_invoice_id', 'branch_id', 'customer_id',
        'financial_account_id', 'payment_method_id', 'refund_account_id', 'refund_date',
        'currency', 'amount', 'reference', 'reason', 'idempotency_key', 'status', 'created_by',
    ];

    protected $attributes = ['status' => 'draft'];

    protected $casts = [
        'refund_date' => 'date', 'amount' => 'decimal:4',
        'posted_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function originalPayment() { return $this->belongsTo(Payment::class, 'original_payment_id'); }
    public function salesInvoice() { return $this->belongsTo(SalesInvoice::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Counterparty::class, 'customer_id'); }
    public function financialAccount() { return $this->belongsTo(FinancialAccount::class); }
    public function paymentMethod() { return $this->belongsTo(PaymentMethod::class); }
    public function refundAccount() { return $this->belongsTo(ChartAccount::class, 'refund_account_id'); }
    public function journalEntries() { return $this->hasMany(JournalEntry::class); }
}
