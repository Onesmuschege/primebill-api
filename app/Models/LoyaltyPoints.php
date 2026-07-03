<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPoint extends Model
{
    protected $fillable = [
        'client_id', 'points', 'type', 'reason',
        'reference_type', 'reference_id', 'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function client()    { return $this->belongsTo(Client::class); }
    public function reference() { return $this->morphTo(); }

    // ── Service helper ──────────────────────────────────────────────────────

    public static function award(int $clientId, int $points, string $reason, $reference = null): void
    {
        static::create([
            'client_id'      => $clientId,
            'points'         => $points,
            'type'           => 'earned',
            'reason'         => $reason,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id'   => $reference?->id,
        ]);

        Client::whereKey($clientId)->increment('loyalty_points_balance', $points);
    }

    public static function redeem(int $clientId, int $points, string $reason, $reference = null): bool
    {
        $client = Client::find($clientId);
        if (!$client || $client->loyalty_points_balance < $points) return false;

        static::create([
            'client_id'      => $clientId,
            'points'         => -$points,
            'type'           => 'redeemed',
            'reason'         => $reason,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id'   => $reference?->id,
        ]);

        $client->decrement('loyalty_points_balance', $points);
        return true;
    }
}