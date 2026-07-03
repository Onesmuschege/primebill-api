<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Voucher extends Model
{
    protected $fillable = [
        'code', 'plan_id', 'created_by', 'redeemed_by',
        'status', 'batch', 'batch_label', 'redeemed_at', 'expires_at',
    ];

    protected $casts = [
        'redeemed_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    public function plan()      { return $this->belongsTo(Plan::class); }
    public function creator()   { return $this->belongsTo(User::class, 'created_by'); }
    public function redeemedBy(){ return $this->belongsTo(Client::class, 'redeemed_by'); }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public static function generateCode(int $length = 8): string
    {
        do {
            $code = strtoupper(Str::random($length));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}