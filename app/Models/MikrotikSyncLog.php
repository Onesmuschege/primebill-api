<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MikrotikSyncLog extends Model
{
    protected $fillable = [
        'log_message',
    ];
}
