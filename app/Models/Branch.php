<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'inventory_account_id', 'due_from_account_id', 'due_to_account_id', 'is_active', 'legacy_source', 'legacy_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function inventoryAccount()
    {
        return $this->belongsTo(ChartAccount::class, 'inventory_account_id');
    }

    public function dueFromAccount()
    {
        return $this->belongsTo(ChartAccount::class, 'due_from_account_id');
    }

    public function dueToAccount()
    {
        return $this->belongsTo(ChartAccount::class, 'due_to_account_id');
    }
}
