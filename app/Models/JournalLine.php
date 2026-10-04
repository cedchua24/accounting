<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    use HasFactory;

    protected $fillable = ['branch_id', 'chart_account_id', 'counterparty_id', 'debit', 'credit', 'description'];

    protected $casts = ['debit' => 'decimal:4', 'credit' => 'decimal:4'];

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function chartAccount()
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
