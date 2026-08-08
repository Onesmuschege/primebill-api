<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class NetworkEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'event_type', 'severity',
        'client_id', 'client_account_id', 'nas_id', 'radius_session_id',
        'message', 'context', 'source', 'occurred_at',
    ];

    protected $casts = [
        'context'     => 'array',
        'occurred_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function account()
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function nas()
    {
        return $this->belongsTo(Router::class, 'nas_id');
    }

    public function radiusSession()
    {
        return $this->belongsTo(RadiusSession::class);
    }
}
