<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialInstitution extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'type', 'is_active', 'legacy_source', 'legacy_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function accounts()
    {
        return $this->hasMany(FinancialAccount::class);
    }
}
