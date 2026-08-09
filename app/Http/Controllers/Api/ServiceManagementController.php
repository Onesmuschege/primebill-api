<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\ClientAccountAddon;
use App\Models\ClientAccountHistory;
use App\Models\ProvisioningProfile;
use App\Models\ServiceAddon;
use App\Models\ServiceChange;
use App\Models\ServiceRelocation;
use App\Models\ServiceTemplate;

/**
 * ServiceManagementController
 *
 * Domain A — Service Management (reconciliation): service templates,
 * provisioning profiles, addons, relocations, changes and account history.
 * Thin, tenant-scoped CRUD reusing the catalog trait.
 */
class ServiceManagementController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'service-templates' => [
            'model' => ServiceTemplate::class,
            'search' => ['name'],
            'with' => ['creator'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'provisioning-profiles' => [
            'model' => ProvisioningProfile::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'service-addons' => [
            'model' => ServiceAddon::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'client-account-addons' => [
            'model' => ClientAccountAddon::class,
            'search' => ['client_id', 'status'],
            'rules' => [
                'client_id' => 'required|exists:clients,id',
                'service_addon_id' => 'required|exists:service_addons,id',
            ],
        ],
        'service-relocations' => [
            'model' => ServiceRelocation::class,
            'search' => ['status'],
            'rules' => ['client_account_id' => 'required|exists:client_accounts,id'],
        ],
        'service-changes' => [
            'model' => ServiceChange::class,
            'search' => ['status', 'type'],
            'rules' => ['client_account_id' => 'required|exists:client_accounts,id'],
        ],
        'account-histories' => [
            'model' => ClientAccountHistory::class,
            'search' => ['action', 'field'],
        ],
    ];
}
