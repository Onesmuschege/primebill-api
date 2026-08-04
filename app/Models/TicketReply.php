<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class TicketReply extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'ticket_id', 'user_id', 'message', 'is_internal',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
