<?php

namespace App\Models;

use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class DunningStep extends Model
{
    use BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'DunningStep';

    protected $fillable = [
        'tenant_id',
        'name',
        'sequence',
        'action',
        'days_after_due',
        'template',
        'is_active',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'days_after_due' => 'integer',
        'is_active' => 'boolean',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(DunningRun::class);
    }
}
