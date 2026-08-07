<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientCustomFieldValue extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'ClientCustomFieldValue';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'client_custom_field_id',
        'value',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function field()
    {
        return $this->belongsTo(ClientCustomField::class, 'client_custom_field_id');
    }
}
