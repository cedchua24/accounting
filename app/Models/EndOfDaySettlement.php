<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EndOfDaySettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'business_date', 'destination_financial_account_id', 'currency',
        'gross_collection_amount', 'transfer_amount', 'status', 'memo', 'created_by',
        'posted_by', 'posted_at', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'business_date' => 'date', 'gross_collection_amount' => 'decimal:4',
        'transfer_amount' => 'decimal:4', 'posted_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'end_of_day_settlement_branches')->withTimestamps();
    }

    public function destinationAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_financial_account_id');
    }

    public function items()
    {
        return $this->hasMany(EndOfDaySettlementItem::class);
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }
}
