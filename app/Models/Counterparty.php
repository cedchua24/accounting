<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Counterparty extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'code', 'name', 'email', 'phone', 'address', 'is_active', 'legacy_source', 'legacy_id'];

    protected $casts = ['is_active' => 'boolean'];
}
