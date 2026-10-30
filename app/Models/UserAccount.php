<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'code', 'type', 'name', 'email', 'phone', 'address',
        'tax_identifier', 'details', 'is_active',
    ];

    protected $casts = [
        'details' => 'array',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function financialAccounts()
    {
        return $this->hasMany(FinancialAccount::class, 'account_holder');
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }
}
