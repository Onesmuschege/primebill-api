<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPoints extends Model
{
    protected $fillable = ['client_id', 'balance', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function transactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function addPoints(int $points, string $reason, ?string $referenceType = null, ?int $referenceId = null): void
    {
        $this->increment('balance', $points);
        $this->transactions()->create([
            'points' => $points,
            'type' => 'earned',
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }

    public function redeemPoints(int $points, string $reason): bool
    {
        if ($this->balance < $points) return false;
        
        $this->decrement('balance', $points);
        $this->transactions()->create([
            'points' => -$points,
            'type' => 'redeemed',
            'reason' => $reason,
        ]);
        return true;
    }
}
