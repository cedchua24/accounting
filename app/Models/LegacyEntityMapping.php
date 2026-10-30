<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyEntityMapping extends Model
{
    protected $fillable = [
        'source_system', 'legacy_table', 'legacy_id',
        'central_type', 'central_id', 'metadata',
    ];

    protected $casts = [
        'legacy_id' => 'integer',
        'central_id' => 'integer',
        'metadata' => 'array',
    ];
}
