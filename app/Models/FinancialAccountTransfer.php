<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialAccountTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'source_financial_account_id', 'destination_financial_account_id',
        'transfer_date', 'currency', 'amount', 'reference', 'memo', 'status',
        'created_by', 'posted_by', 'posted_at', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'transfer_date' => 'date', 'amount' => 'decimal:4',
        'posted_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function sourceAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'source_financial_account_id');
    }

    public function destinationAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_financial_account_id');
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }
}
