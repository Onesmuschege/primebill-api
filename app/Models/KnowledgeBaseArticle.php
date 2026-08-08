<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class KnowledgeBaseArticle extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'category_id',
        'title',
        'slug',
        'summary',
        'content',
        'excerpt',
        'author_type',
        'author_id',
        'attachments',
        'tags',
        'views',
        'helpful_count',
        'not_helpful_count',
        'is_published',
        'is_featured',
        'priority',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'attachments' => 'array',
        'tags' => 'array',
        'views' => 'integer',
        'helpful_count' => 'integer',
        'not_helpful_count' => 'integer',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'priority' => 'integer',
        'metadata' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBaseCategory::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPublished(): bool
    {
        return $this->is_published;
    }

    public function isFeatured(): bool
    {
        return $this->is_featured;
    }
}
