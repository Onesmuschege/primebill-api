<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class OntSignalHistory extends Model
{
         use BelongsToTenant;

    /**
     * Migration creates this table with a singular name that does not
     * match Eloquent's default plural convention.
     */
    protected $table = 'ont_signal_history';

    protected $fillable = [
        'tenant_id',
        'ont_id',
        'rx_power',
        'tx_power',
        'temperature',
        'voltage',
        'bias_current',
        'notes',
        'metadata',
        'collected_by',
    ];

    protected $casts = [
        'rx_power' => 'decimal:2',
        'tx_power' => 'decimal:2',
        'temperature' => 'decimal:2',
        'voltage' => 'decimal:2',
        'bias_current' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function ont(): BelongsTo
    {
        return $this->belongsTo(Ont::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
