<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InterbranchOrder extends Model
{
    use HasFactory;

    protected $fillable = ['number', 'source_branch_id', 'destination_branch_id', 'order_date', 'currency', 'amount', 'settlement_mode', 'status', 'memo', 'created_by'];

    protected $attributes = ['status' => 'draft', 'settlement_mode' => 'internal_clearing'];

    protected $casts = ['order_date' => 'date', 'amount' => 'decimal:4', 'posted_at' => 'datetime', 'voided_at' => 'datetime'];

    public function sourceBranch()
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch()
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }
}
