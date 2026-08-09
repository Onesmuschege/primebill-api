<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Models\MaintenanceNotice;
use App\Models\SlaPolicy;
use App\Models\SlaRule;
use App\Models\TicketCategory;
use App\Models\TicketEscalation;
use App\Models\TicketQueue;

/**
 * SupportCatalogController
 *
 * Domain H — Support/SLA: departments, queues, categories, SLA policies/rules,
 * escalations, knowledge base and customer communications (announcements,
 * maintenance notices).
 */
class SupportCatalogController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'departments' => [
            'model' => Department::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'ticket-queues' => [
            'model' => TicketQueue::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'ticket-categories' => [
            'model' => TicketCategory::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'sla-policies' => [
            'model' => SlaPolicy::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'sla-rules' => [
            'model' => SlaRule::class,
            'rules' => [
                'sla_policy_id' => 'required|exists:sla_policies,id',
                'name' => 'required|string|max:255',
            ],
        ],
        'ticket-escalations' => [
            'model' => TicketEscalation::class,
            'rules' => ['ticket_id' => 'required|exists:tickets,id'],
        ],
        'kb-articles' => [
            'model' => KnowledgeBaseArticle::class,
            'search' => ['title', 'slug'],
            'rules' => ['title' => 'required|string|max:255'],
        ],
        'kb-categories' => [
            'model' => KnowledgeBaseCategory::class,
            'search' => ['name', 'slug'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'announcements' => [
            'model' => Announcement::class,
            'search' => ['title'],
            'rules' => ['title' => 'required|string|max:255'],
        ],
        'maintenance-notices' => [
            'model' => MaintenanceNotice::class,
            'search' => ['title'],
            'rules' => ['title' => 'required|string|max:255'],
        ],
    ];
}
