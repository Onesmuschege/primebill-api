<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class CustomerSatisfaction extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'type',
        'source',
        'score',
        'max_score',
        'category',
        'comment',
        'responses',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'score' => 'integer',
        'max_score' => 'integer',
        'responses' => 'array',
        'metadata' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getPercentageAttribute(): int
    {
        return $this->max_score > 0 ? (int) round(($this->score / $this->max_score) * 100) : 0;
    }
}
