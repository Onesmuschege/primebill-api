<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class SavedReport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'type',
        'description',
        'filters',
        'columns',
        'grouping',
        'sorting',
        'visualization',
        'is_public',
        'is_favorite',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'filters' => 'array',
        'columns' => 'array',
        'grouping' => 'array',
        'sorting' => 'array',
        'visualization' => 'array',
        'metadata' => 'array',
        'is_public' => 'boolean',
        'is_favorite' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPublic(): bool
    {
        return $this->is_public;
    }

    public function isFavorite(): bool
    {
        return $this->is_favorite;
    }
}
