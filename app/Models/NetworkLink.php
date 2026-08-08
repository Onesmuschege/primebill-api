<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class NetworkLink extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'NetworkLink';

    protected $fillable = [
        'device_a_id', 'device_b_id', 'interface_a',
        'interface_b', 'media', 'status', 'description',
    ];

    public function deviceA()
    {
        return $this->belongsTo(Router::class, 'device_a_id');
    }

    public function deviceB()
    {
        return $this->belongsTo(Router::class, 'device_b_id');
    }
}
