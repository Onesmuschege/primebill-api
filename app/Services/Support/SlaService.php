<?php

namespace App\Services\Support;

use App\Models\SlaPolicy;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketEscalation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Service Desk SLA enforcement.
 *
 *  - resolvePolicy: pick the best active SlaPolicy for a ticket (closest to its
 *    priority level), so the right response/resolution targets apply.
 *  - applyPolicy: stamp the ticket with its policy + due timestamps.
 *  - markResponded: record the first-response timestamp (respets the response SLA).
 *  - evaluate: scan open tickets, detect response/resolution breaches, mark
 *    sla_breached and create ticket_escalations rows (levels auto-increment).
 *
 * Run on a schedule via `sla:evaluate`; safe in console (BelongsToTenant leaves
 * queries unscoped when no tenant is bound, so it sweeps all tenants).
 */
class SlaService
{
    public const PRIORITY_LEVEL = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];

    public function resolvePolicy(Ticket $ticket): ?SlaPolicy
    {
        $level = self::PRIORITY_LEVEL[$ticket->priority] ?? 1;

        $policies = SlaPolicy::where('tenant_id', $ticket->tenant_id)
            ->where('is_active', true)
            ->get();

        if ($policies->isEmpty()) {
            return null;
        }

        return $policies
            ->sortBy(fn (SlaPolicy $p) => [abs($p->priority_level - $level), $p->priority_level])
            ->first();
    }

    public function applyPolicy(Ticket $ticket): Ticket
    {
        $policy = $this->resolvePolicy($ticket);
        if (! $policy) {
            return $ticket;
        }

        $now = Carbon::now();
        $ticket->sla_policy_id = $policy->id;
        if ($policy->response_time_minutes !== null) {
            $ticket->sla_response_due_at = $now->copy()->addMinutes($policy->response_time_minutes);
        }
        if ($policy->resolution_time_minutes !== null) {
            $ticket->sla_resolution_due_at = $now->copy()->addMinutes($policy->resolution_time_minutes);
        } elseif ($policy->escalation_after_minutes !== null) {
            $ticket->sla_resolution_due_at = $now->copy()->addMinutes($policy->escalation_after_minutes);
        }
        $ticket->last_sla_evaluated_at = $now;
        $ticket->save();

        return $ticket;
    }

    public function markResponded(Ticket $ticket): Ticket
    {
        if ($ticket->first_responded_at === null) {
            $ticket->first_responded_at = Carbon::now();
            $ticket->save();
        }

        return $ticket;
    }

    /**
     * Evaluate all open tickets with a policy, flag breaches and auto-escalate.
     *
     * @return array{evaluated: int, breaches: int, escalations: int}
     */
    public function evaluate(int $limit = 500): array
    {
        $query = Ticket::query()
            ->whereIn('status', ['open', 'pending'])
            ->whereNotNull('sla_policy_id');

        if (Tenant::current()) {
            $query->where('tenant_id', Tenant::current()->id);
        }

        $now = Carbon::now();
        $evaluated = 0;
        $breaches = 0;
        $escalations = 0;

        $query->with('slaPolicy')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Ticket $ticket) use (&$evaluated, &$breaches, &$escalations, $now) {
                $evaluated++;
                $policy = $ticket->slaPolicy;
                if (! $policy) {
                    $ticket->update(['last_sla_evaluated_at' => $now]);
                    return;
                }

                $summary = $this->assess($ticket, $policy, $now);

                if ($summary['resolution_overdue'] && ! $ticket->sla_breached) {
                    $ticket->sla_breached = true;
                    $breaches++;
                }

                // Rule-driven escalations (priority_match / response_overdue / resolution_overdue)
                $this->evaluateRules($ticket, $policy, $summary, $escalations);

                // Policy-level escalation once an open ticket passes its escalation window
                if ($this->shouldEscalate($policy, $ticket, $summary, $now)
                    && $this->createEscalation($ticket, $policy, 'sla_breach')) {
                    $escalations++;
                }

                $ticket->last_sla_evaluated_at = $now;
                $ticket->save();
            });

        return compact('evaluated', 'breaches', 'escalations');
    }

    protected function assess(Ticket $ticket, SlaPolicy $policy, Carbon $now): array
    {
        return [
            'response_overdue' => $ticket->first_responded_at === null
                && $ticket->sla_response_due_at !== null
                && $now->gt($ticket->sla_response_due_at),
            'resolution_overdue' => $ticket->sla_resolution_due_at !== null
                && $now->gt($ticket->sla_resolution_due_at),
        ];
    }

    protected function shouldEscalate(SlaPolicy $policy, Ticket $ticket, array $summary, Carbon $now): bool
    {
        if (! $policy->escalation_enabled) {
            return false;
        }
        if ($summary['response_overdue'] || $summary['resolution_overdue']) {
            return true;
        }
        return $policy->escalation_after_minutes !== null
            && $now->gt($ticket->created_at->copy()->addMinutes($policy->escalation_after_minutes));
    }

    protected function evaluateRules(Ticket $ticket, SlaPolicy $policy, array $summary, int &$escalations): void
    {
        foreach ($policy->rules as $rule) {
            if (! $rule->is_active) {
                continue;
            }
            $actions = $rule->actions ?? [];

            $matches = match ($rule->condition_type) {
                'priority_match' => (self::PRIORITY_LEVEL[$ticket->priority] ?? 1)
                    >= (int) ($rule->conditions['priority_level'] ?? 0),
                'response_overdue'   => (bool) ($summary['response_overdue'] ?? false),
                'resolution_overdue' => (bool) ($summary['resolution_overdue'] ?? false),
                default => false,
            };

            if ($matches && ($actions['escalate'] ?? false)
                && $this->createEscalation($ticket, $policy, 'rule_match')) {
                $escalations++;
            }
        }
    }

    protected function createEscalation(Ticket $ticket, SlaPolicy $policy, string $trigger): bool
    {
        if (TicketEscalation::where('ticket_id', $ticket->id)->whereNull('resolved_at')->exists()) {
            return false;
        }

        $level = (int) (TicketEscalation::where('ticket_id', $ticket->id)->max('escalation_level') ?? 0) + 1;

        TicketEscalation::create([
            'tenant_id'        => $ticket->tenant_id,
            'ticket_id'        => $ticket->id,
            'sla_policy_id'    => $policy->id,
            'escalated_to'     => $ticket->assigned_to,
            'escalation_level' => $level,
            'trigger'          => $trigger,
            'reason'           => $this->buildEscalationReason($ticket),
            'created_by'       => null,
        ]);

        Log::info('SLA auto-escalation', ['ticket_id' => $ticket->id, 'level' => $level, 'trigger' => $trigger]);

        return true;
    }

    protected function buildEscalationReason(Ticket $ticket): string
    {
        $bits = ['Ticket #' . $ticket->id . " ('" . $ticket->subject . "')"];
        if ($ticket->sla_breached) {
            $bits[] = 'resolution SLA breached';
        } elseif ($ticket->first_responded_at === null && $ticket->sla_response_due_at !== null) {
            $bits[] = 'no first response before SLA target';
        }
        return implode(' — ', $bits) . '.';
    }
}