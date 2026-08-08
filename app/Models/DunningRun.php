<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class DunningRun extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'invoice_id',
        'dunning_step_id',
        'status',
        'executed_at',
        'notes',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function dunningStep(): BelongsTo
    {
        return $this->belongsTo(DunningStep::class);
    }
}
