<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'type', 'creates_obligation', 'is_active'];

    protected $casts = ['creates_obligation' => 'boolean', 'is_active' => 'boolean'];
}
