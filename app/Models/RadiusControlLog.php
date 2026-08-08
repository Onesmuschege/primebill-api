<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class RadiusControlLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'action', 'radius_session_id', 'client_account_id', 'nas_id',
        'username', 'session_id', 'status', 'result',
        'request', 'response', 'error', 'attempts',
        'created_at', 'completed_at',
    ];

    protected $casts = [
        'request'      => 'array',
        'response'     => 'array',
        'created_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function radiusSession()
    {
        return $this->belongsTo(RadiusSession::class);
    }

    public function account()
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function nas()
    {
        return $this->belongsTo(Router::class, 'nas_id');
    }
}
