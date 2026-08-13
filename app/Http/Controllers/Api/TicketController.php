<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\StoreTicketRequest;
use App\Http\Requests\Ticket\ReplyTicketRequest;
use App\Models\Ticket;
use App\Services\Support\TicketService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    protected TicketService $ticketService;

    public function __construct(TicketService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    // GET /api/tickets
    public function index(Request $request)
    {
        $tickets = $this->ticketService->getAllTickets($request);

        return response()->json([
            'success' => true,
            'data'    => $tickets,
        ]);
    }

    // POST /api/tickets
    public function store(StoreTicketRequest $request)
    {
        $ticket = $this->ticketService->createTicket(
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Ticket created successfully',
            'data'    => $ticket,
        ], 201);
    }

    // GET /api/tickets/{id}
    public function show(Ticket $ticket)
    {
        $ticket->load('client', 'assignedTo', 'replies.user', 'workOrder', 'knowledgeRefs.article', 'knowledgeRefs.creator');

        return response()->json([
            'success' => true,
            'data'    => $ticket,
        ]);
    }

    // PUT /api/tickets/{id}
    public function update(Request $request, Ticket $ticket)
    {
        $request->validate([
            'status'   => 'sometimes|in:open,pending,solved,closed',
            'priority' => 'sometimes|in:low,medium,high,critical',
        ]);

        $ticket->update($request->only('status', 'priority'));

        return response()->json([
            'success' => true,
            'message' => 'Ticket updated successfully',
            'data'    => $ticket,
        ]);
    }

    // POST /api/tickets/{id}/reply
    public function reply(ReplyTicketRequest $request, Ticket $ticket)
    {
        $reply = $this->ticketService->replyTicket(
            $ticket,
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Reply added successfully',
            'data'    => $reply,
        ], 201);
    }

    // POST /api/tickets/{id}/assign
    public function assign(Request $request, Ticket $ticket)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $ticket = $this->ticketService->assignTicket(
            $ticket,
            $request->user_id,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Ticket assigned successfully',
            'data'    => $ticket,
        ]);
    }

    // POST /api/tickets/{id}/close
    public function close(Request $request, Ticket $ticket)
    {
        $ticket = $this->ticketService->closeTicket(
            $ticket,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Ticket closed successfully',
            'data'    => $ticket,
        ]);
    }

    // POST /api/tickets/{id}/escalate
    public function escalate(Request $request, Ticket $ticket)
    {
        $ticket = $this->ticketService->escalateTicket(
            $ticket,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Ticket escalated successfully',
            'data'    => $ticket,
        ]);
    }

    // POST /api/tickets/{id}/work-order — link a ticket to its field-ops dispatch
    public function linkWorkOrder(Request $request, Ticket $ticket)
    {
        $request->validate([
            'work_order_id' => ['required', 'exists:work_orders,id'],
        ]);

        $ticket->update(['work_order_id' => $request->work_order_id]);

        return response()->json([
            'success' => true,
            'message' => 'Work order linked to ticket',
            'data'    => $ticket->load('workOrder'),
        ]);
    }

    // POST /api/tickets/{id}/unlink-work-order
    public function unlinkWorkOrder(Ticket $ticket)
    {
        $ticket->update(['work_order_id' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Work order unlinked from ticket',
            'data'    => $ticket->fresh(),
        ]);
    }

    // GET /api/tickets/{id}/knowledge — KB articles referenced on this ticket
    public function knowledgeRefs(Ticket $ticket)
    {
        $refs = $ticket->knowledgeRefs()->with(['article:id,title,slug', 'creator:id,name'])->get();

        return response()->json(['success' => true, 'data' => $refs]);
    }

    // POST /api/tickets/{id}/knowledge — attach a KB reference
    public function addKnowledgeRef(Request $request, Ticket $ticket)
    {
        $data = $request->validate([
            'knowledge_base_article_id' => ['required', 'exists:knowledge_base_articles,id'],
            'note' => ['nullable', 'string'],
        ]);

        $ref = \App\Models\TicketKnowledgeRef::updateOrCreate(
            [
                'ticket_id'                    => $ticket->id,
                'knowledge_base_article_id'    => $data['knowledge_base_article_id'],
            ],
            [
                'note'       => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Knowledge reference attached',
            'data'    => $ref->load(['article:id,title,slug', 'creator:id,name']),
        ], 201);
    }

    // DELETE /api/tickets/{id}/knowledge/{ref}
    public function removeKnowledgeRef(Ticket $ticket, \App\Models\TicketKnowledgeRef $ref)
    {
        if ($ref->ticket_id !== $ticket->id) {
            return response()->json(['success' => false, 'message' => 'Reference does not belong to this ticket'], 422);
        }

        $ref->delete();

        return response()->json(['success' => true, 'message' => 'Knowledge reference removed']);
    }

    // GET /api/tickets/stats
    public function stats()
    {
        $stats = $this->ticketService->getStats();

        return response()->json([
            'success' => true,
            'data'    => $stats,
        ]);
    }
}
