<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = ['branch_id', 'number', 'payee_id', 'chart_account_id', 'expense_date', 'currency', 'amount', 'status', 'description'];

    protected $casts = ['expense_date' => 'date', 'amount' => 'decimal:4'];

    public function payee()
    {
        return $this->belongsTo(Counterparty::class, 'payee_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function chartAccount()
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
