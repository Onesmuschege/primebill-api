<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Ticket extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'client_id', 'assigned_to', 'subject',
        'description', 'priority', 'status', 'closed_at',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies()
    {
        return $this->hasMany(TicketReply::class);
    }
}
