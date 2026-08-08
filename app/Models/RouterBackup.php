<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class RouterBackup extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'router_id',
        'filename',
        'checksum',
        'size',
        'notes',
        'success',
        'error_message',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'success' => 'boolean',
        'metadata' => 'array',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
