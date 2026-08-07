<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientTag extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'ClientTag';

    protected $fillable = [
        'tenant_id',
        'name',
        'color',
        'description',
    ];

    protected $casts = [
        'color' => 'string',
    ];

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'client_tag_assignments', 'client_tag_id', 'client_id')
            ->withPivot('tenant_id', 'assigned_by')
            ->withTimestamps();
    }
}
