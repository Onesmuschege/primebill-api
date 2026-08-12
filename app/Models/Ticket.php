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
        'sla_policy_id', 'first_responded_at',
        'sla_response_due_at', 'sla_resolution_due_at',
        'sla_breached', 'last_sla_evaluated_at',
    ];

    protected $casts = [
        'closed_at'             => 'datetime',
        'first_responded_at'    => 'datetime',
        'sla_response_due_at'   => 'datetime',
        'sla_resolution_due_at' => 'datetime',
        'last_sla_evaluated_at' => 'datetime',
        'sla_breached'          => 'boolean',
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

    public function slaPolicy()
    {
        return $this->belongsTo(SlaPolicy::class);
    }

    public function escalations()
    {
        return $this->hasMany(TicketEscalation::class);
    }
}
