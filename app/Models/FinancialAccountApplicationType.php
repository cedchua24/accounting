<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialAccountApplicationType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'description', 'display_order', 'is_active',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function applications()
    {
        return $this->hasMany(FinancialAccountApplication::class, 'application_id');
    }
}
