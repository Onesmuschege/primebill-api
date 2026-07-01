<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'code', 'plan_id', 'status', 'redeemed_by',
        'redeemed_at', 'expires_at', 'created_by',
    ];

    protected $casts = [
        'redeemed_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function redeemedBy()
    {
        return $this->belongsTo(Client::class, 'redeemed_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && now() > $this->expires_at;
    }
}
