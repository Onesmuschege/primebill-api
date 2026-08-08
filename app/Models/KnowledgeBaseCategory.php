<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class KnowledgeBaseCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'icon',
        'priority',
        'is_active',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBaseCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(KnowledgeBaseCategory::class, 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(KnowledgeBaseArticle::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
