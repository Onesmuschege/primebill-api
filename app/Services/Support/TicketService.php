<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\SystemLog;
use App\Jobs\SendSmsJob;
use Illuminate\Http\Request;

class TicketService
{
    public function getAllTickets(Request $request)
    {
        $query = Ticket::with('client', 'assignedTo');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')
                     ->paginate($request->per_page ?? 15);
    }

    public function createTicket(array $data, $userId): Ticket
    {
        $data['status']   = 'open';
        $data['priority'] = $data['priority'] ?? 'medium';

        $ticket = Ticket::create($data);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'created ticket',
            'model'      => 'Ticket',
            'model_id'   => $ticket->id,
            'new_values' => $data,
        ]);

        return $ticket->load('client');
    }

    public function replyTicket(Ticket $ticket, array $data, $userId): TicketReply
    {
        $reply = TicketReply::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $userId,
            'message'     => $data['message'],
            'is_internal' => $data['is_internal'] ?? false,
        ]);

        // Update ticket status to pending if open
        if ($ticket->status === 'open') {
            $ticket->update(['status' => 'pending']);
        }

        // Notify client via SMS if not internal note
        if (!$reply->is_internal && $ticket->client) {
            SendSmsJob::dispatch(
                $ticket->client->phone,
                "Dear {$ticket->client->first_name}, your ticket #{$ticket->id} has been updated. Login to view the reply.",
                $ticket->client_id,
                $ticket->client->tenant_id
            );
        }

        return $reply->load('user');
    }

    public function assignTicket(Ticket $ticket, int $userId, $assignedBy): Ticket
    {
        $ticket->update(['assigned_to' => $userId]);

        SystemLog::create([
            'user_id'  => $assignedBy,
            'action'   => 'assigned ticket',
            'model'    => 'Ticket',
            'model_id' => $ticket->id,
        ]);

        return $ticket->load('assignedTo');
    }

    public function closeTicket(Ticket $ticket, $userId): Ticket
    {
        $ticket->update([
            'status'    => 'closed',
            'closed_at' => now(),
        ]);

        SystemLog::create([
            'user_id'  => $userId,
            'action'   => 'closed ticket',
            'model'    => 'Ticket',
            'model_id' => $ticket->id,
        ]);

        // Notify client
        if ($ticket->client) {
            SendSmsJob::dispatch(
                $ticket->client->phone,
                "Dear {$ticket->client->first_name}, your ticket #{$ticket->id} has been closed. Thank you.",
                $ticket->client_id,
                $ticket->client->tenant_id
            );
        }

        return $ticket;
    }

    public function escalateTicket(Ticket $ticket, $userId): Ticket
    {
        $ticket->update(['priority' => 'critical']);

        SystemLog::create([
            'user_id'  => $userId,
            'action'   => 'escalated ticket',
            'model'    => 'Ticket',
            'model_id' => $ticket->id,
        ]);

        return $ticket;
    }

    public function getStats(): array
    {
        return [
            'open'    => Ticket::where('status', 'open')->count(),
            'pending' => Ticket::where('status', 'pending')->count(),
            'solved'  => Ticket::where('status', 'solved')->count(),
            'closed'  => Ticket::where('status', 'closed')->count(),
            'total'   => Ticket::count(),
        ];
    }
}
