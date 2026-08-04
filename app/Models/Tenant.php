<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = [
        'name', 'slug', 'status', 'plan', 'timezone', 'currency',
    ];

    /**
     * The currently-resolved tenant for this request, set by the
     * ResolveTenant middleware early in the request lifecycle.
     * Returns null outside a request context (e.g. artisan commands
     * that haven't explicitly set one) — callers must handle that case.
     */
    public static function current(): ?self
    {
        return app()->bound('currentTenant') ? app('currentTenant') : null;
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }
}