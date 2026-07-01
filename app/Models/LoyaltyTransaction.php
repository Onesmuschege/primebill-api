<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    protected $fillable = [
        'loyalty_points_id', 'points', 'type', 'reason',
        'reference_type', 'reference_id',
    ];

    public function loyaltyPoints()
    {
        return $this->belongsTo(LoyaltyPoints::class);
    }
}
