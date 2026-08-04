<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class SalesCommission extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id', 'client_id', 'invoice_id',
        'commission_rate', 'amount', 'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
