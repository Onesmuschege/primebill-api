<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class WorkOrderTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'type',
        'category',
        'estimated_duration',
        'required_skills',
        'required_equipment',
        'checklist',
        'instructions',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'required_skills' => 'array',
        'required_equipment' => 'array',
        'checklist' => 'array',
        'metadata' => 'array',
        'estimated_duration' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
