<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\RouterBackup;
use App\Models\RouterCommandLog;
use App\Models\RouterConfiguration;
use App\Models\RouterInterface;
use App\Models\RouterTemplate;

/**
 * RouterConfigurationController
 *
 * Domain C — Network Configuration: router interfaces, reusable templates,
 * versioned configurations, backups and a command audit log.
 */
class RouterConfigurationController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'router-interfaces' => [
            'model' => RouterInterface::class,
            'search' => ['name', 'type'],
            'rules' => [
                'router_id' => 'required|exists:routers,id',
                'name' => 'required|string|max:255',
            ],
        ],
        'router-templates' => [
            'model' => RouterTemplate::class,
            'search' => ['name'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'router-configurations' => [
            'model' => RouterConfiguration::class,
            'rules' => ['router_id' => 'required|exists:routers,id'],
        ],
        'router-backups' => [
            'model' => RouterBackup::class,
            'rules' => ['router_id' => 'required|exists:routers,id'],
        ],
        'router-command-logs' => [
            'model' => RouterCommandLog::class,
            'rules' => [
                'router_id' => 'required|exists:routers,id',
                'command' => 'required|string',
            ],
        ],
    ];
}
