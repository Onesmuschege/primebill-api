<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CommunicationLog;
use App\Models\CommunicationTemplate;
use App\Models\NotificationPreference;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CommunicationsController
 *
 * Domain I — Communications: templates, delivery logs, notification
 * preferences, bulk campaigns and outbound webhooks.
 */
class CommunicationsController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'communication-templates' => [
            'model' => CommunicationTemplate::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'communication-logs' => [
            'model' => CommunicationLog::class,
            'search' => ['status'],
            'rules' => [],
        ],
        'notification-preferences' => [
            'model' => NotificationPreference::class,
            'rules' => [],
        ],
        'campaigns' => [
            'model' => Campaign::class,
            'search' => ['name', 'code'],
            'with' => ['recipients'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'campaign-recipients' => [
            'model' => CampaignRecipient::class,
            'search' => ['status'],
            'rules' => [
                'campaign_id' => 'required|exists:campaigns,id',
                'recipient_address' => 'required|string|max:255',
            ],
        ],
        'webhooks' => [
            'model' => Webhook::class,
            'search' => ['name', 'code'],
            'rules' => [
                'name' => 'required|string|max:255',
                'url' => 'required|url|max:255',
            ],
        ],
        'webhook-deliveries' => [
            'model' => WebhookDelivery::class,
            'search' => ['status'],
            'rules' => ['webhook_id' => 'required|exists:webhooks,id'],
        ],
    ];

    /**
     * Move a campaign through its lifecycle (draft → scheduled → sent).
     */
    public function transitionCampaign(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::in(['scheduled', 'sending', 'sent', 'cancelled'])],
        ]);

        $campaign = Campaign::findOrFail((int) $id);
        $campaign->status = $request->input('status');

        if ($request->has('scheduled_at') && $request->input('status') === 'scheduled') {
            $campaign->scheduled_at = $request->input('scheduled_at');
        }

        if ($campaign->status === 'sent') {
            $campaign->sent_at = now();
            $campaign->sent_count = $campaign->recipients()->count();
        }

        $campaign->save();

        app(AuditService::class)->log(
            action: 'campaign.transitioned',
            model: 'Campaign',
            modelId: $campaign->id,
            newValues: ['status' => $campaign->status],
        );

        return response()->json([
            'success' => true,
            'message' => 'Campaign '.$campaign->status,
            'data' => $campaign->load('recipients'),
        ]);
    }
}
