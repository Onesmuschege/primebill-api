<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class OntEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'ont_id',
        'event',
        'severity',
        'description',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function ont(): BelongsTo
    {
        return $this->belongsTo(Ont::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
