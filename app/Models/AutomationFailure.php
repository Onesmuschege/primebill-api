<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationFailure extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'    => 'array',
        'failed_at'  => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }
}
