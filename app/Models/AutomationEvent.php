<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'      => 'array',
        'result'       => 'array',
        'completed_at' => 'datetime',
    ];

        public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // Alias matching the common Eloquent convention (type() instead of ofType()).
    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
