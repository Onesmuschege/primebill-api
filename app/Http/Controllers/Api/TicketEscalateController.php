<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\Support\TicketService;
use Illuminate\Http\Request;

class TicketEscalateController extends Controller
{
    protected TicketService $ticketService;

    public function __construct(TicketService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    // POST /api/tickets/{id}/escalate
    public function escalate(Request $request, Ticket $ticket)
    {
        // Mark as escalated by updating priority or status
        $ticket->update([
            'priority' => 'critical',
        ]);

        // Log escalation
        \App\Models\SystemLog::create([
            'action' => 'ticket.escalated',
            'entity_type' => 'Ticket',
            'entity_id' => $ticket->id,
            'description' => "Ticket #{$ticket->id} escalated to critical priority",
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket escalated successfully',
            'data'    => $ticket->fresh(),
        ]);
    }
}
