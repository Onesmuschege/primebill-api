<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Expenditure extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'category', 'description',
        'amount', 'date', 'recorded_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
