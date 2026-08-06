<?php

namespace App\Http;

use App\Http\Middleware\EnforceSubscriptionLimits;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     */
    protected $middleware = [
        // Existing middleware...
    ];

    /**
     * The application's route middleware aliases.
     */
    protected $middlewareAliases = [
        // Existing aliases...
        'subscription.limits' => EnforceSubscriptionLimits::class,
        'feature' => \App\Http\Middleware\EnforceFeatureAccess::class,
    ];
}
