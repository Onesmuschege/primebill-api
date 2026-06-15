<?php

namespace App\Providers;

use App\Services\Network\MikroTikRouterAdapter;
use App\Services\Network\MockRouterAdapter;
use App\Services\Network\RouterAdapterInterface;
use App\Services\Radius\FreeRadiusAdapter;
use App\Services\Radius\MockRadiusAdapter;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RouterAdapterInterface::class, function ($app) {
            return match (config('network.router_driver', 'mock')) {
                'mikrotik' => $app->make(MikroTikRouterAdapter::class),
                default    => $app->make(MockRouterAdapter::class),
            };
        });

        $this->app->singleton(RadiusAdapterInterface::class, function ($app) {
            return match (config('network.radius_driver', 'mock')) {
                'freeradius' => $app->make(FreeRadiusAdapter::class),
                default      => $app->make(MockRadiusAdapter::class),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
