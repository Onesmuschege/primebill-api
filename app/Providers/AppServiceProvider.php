<?php

namespace App\Providers;

use App\Services\Audit\AuditService;
use App\Services\Network\AccessMethodManager;
use App\Services\Network\DhcpAccessService;
use App\Services\Network\FupService;
use App\Services\Network\HotspotAccessService;
use App\Services\Network\MikroTikRouterAdapter;
use App\Services\Network\MockRouterAdapter;
use App\Services\Network\NetworkEventService;
use App\Services\Network\PppoeAccessService;
use App\Services\Network\ProvisioningService;
use App\Services\Network\RouterAdapterInterface;
use App\Services\Network\SessionReconciliationService;
use App\Services\Network\ServiceLifecycleService;
use App\Services\Network\StaticIpAccessService;
use App\Services\Radius\FreeRadiusAdapter;
use App\Services\Radius\MockRadiusAdapter;
use App\Services\Radius\RadiusAdapterInterface;
use App\Services\Radius\RadiusControlService;
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

        // Register AuditService as a singleton for centralized audit logging
        $this->app->singleton(AuditService::class, function ($app) {
            return new AuditService();
        });

        // Register access method services
        $this->app->singleton(PppoeAccessService::class, function ($app) {
            return new PppoeAccessService(
                $app->make(RouterAdapterInterface::class),
                $app->make(RadiusAdapterInterface::class)
            );
        });

        $this->app->singleton(HotspotAccessService::class, function ($app) {
            return new HotspotAccessService(
                $app->make(RouterAdapterInterface::class),
                $app->make(RadiusAdapterInterface::class)
            );
        });

        $this->app->singleton(StaticIpAccessService::class, function ($app) {
            return new StaticIpAccessService(
                $app->make(RouterAdapterInterface::class),
                $app->make(RadiusAdapterInterface::class)
            );
        });

        $this->app->singleton(DhcpAccessService::class, function ($app) {
            return new DhcpAccessService(
                $app->make(RouterAdapterInterface::class),
                $app->make(RadiusAdapterInterface::class)
            );
        });

        $this->app->singleton(AccessMethodManager::class, function ($app) {
            return new AccessMethodManager(
                $app->make(PppoeAccessService::class),
                $app->make(HotspotAccessService::class),
                $app->make(StaticIpAccessService::class),
                $app->make(DhcpAccessService::class)
            );
        });

        $this->app->singleton(NetworkEventService::class, function ($app) {
            return new NetworkEventService();
        });

        $this->app->singleton(RadiusControlService::class, function ($app) {
            return new RadiusControlService(
                $app->make(NetworkEventService::class)
            );
        });

        $this->app->singleton(FupService::class, function ($app) {
            return new FupService(
                $app->make(RadiusControlService::class),
                $app->make(NetworkEventService::class)
            );
        });

        $this->app->singleton(SessionReconciliationService::class, function ($app) {
            return new SessionReconciliationService(
                $app->make(NetworkEventService::class)
            );
        });

        $this->app->singleton(ServiceLifecycleService::class, function ($app) {
            return new ServiceLifecycleService(
                $app->make(AccessMethodManager::class),
                $app->make(RadiusControlService::class)
            );
        });

        // Bind ProvisioningService explicitly with resolved dependencies
        $this->app->singleton(ProvisioningService::class, function ($app) {
            return new ProvisioningService(
                $app->make(RouterAdapterInterface::class),
                $app->make(RadiusAdapterInterface::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
