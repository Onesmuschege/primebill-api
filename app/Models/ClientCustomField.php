<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientCustomField extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'ClientCustomField';

    protected $fillable = [
        'tenant_id',
        'name',
        'label',
        'type',
        'options',
        'is_required',
        'is_visible_on_portal',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_visible_on_portal' => 'boolean',
    ];

    public function values()
    {
        return $this->hasMany(ClientCustomFieldValue::class);
    }
}
