<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class ReportDelivery extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'report_schedule_id',
        'saved_report_id',
        'status',
        'format',
        'file_path',
        'file_name',
        'file_size',
        'recipients',
        'error_message',
        'processed_at',
        'sent_at',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'recipients' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class, 'saved_report_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
