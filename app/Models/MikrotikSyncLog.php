<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class MikrotikSyncLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'log_message',
    ];
}
