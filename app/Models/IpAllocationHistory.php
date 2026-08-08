<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class IpAllocationHistory extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'IpAllocationHistory';

    // The migration creates the singular table name explicitly.
    protected $table = 'ip_allocation_history';

    protected $fillable = [
        'ip_allocation_id', 'action', 'ip_address',
        'client_id', 'client_account_id', 'user_id', 'description',
    ];

    public function allocation()
    {
        return $this->belongsTo(IpAllocation::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function clientAccount()
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
