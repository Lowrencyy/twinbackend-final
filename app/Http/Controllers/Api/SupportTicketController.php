<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class SupportTicketController extends Controller
{
    protected function canManageAllTickets($user): bool
    {
        // Every TelcoVantage employee is internal support — any role can view and
        // reply to ALL tickets. (The route group already enforces company:telcovantage.)
        return $user->company === 'telcovantage';
    }

    protected function ensureTicketAccess(Request $request, SupportTicket $supportTicket): void
    {
        $user = $request->user();

        if ($this->canManageAllTickets($user)) {
            return;
        }

        abort_unless((int) $supportTicket->submitted_by === (int) $user->id, 403, 'You do not have access to this ticket.');
    }

    protected function isInternalSupport($user): bool
    {
        return $this->canManageAllTickets($user);
    }

    protected function latestActivityAt(SupportTicket $ticket): ?Carbon
    {
        return $ticket->last_message_at
            ?? $ticket->updated_at
            ?? $ticket->created_at;
    }

    protected function hasUnreadForUser(SupportTicket $ticket, $user): bool
    {
        $latestActivityAt = $this->latestActivityAt($ticket);
        if (! $latestActivityAt) {
            return false;
        }

        if ($this->isInternalSupport($user)) {
            return ! $ticket->admin_last_read_at || $ticket->admin_last_read_at->lt($latestActivityAt);
        }

        return ! $ticket->requester_last_read_at || $ticket->requester_last_read_at->lt($latestActivityAt);
    }

    protected function markAsRead(SupportTicket $ticket, $user): void
    {
        $column = $this->isInternalSupport($user) ? 'admin_last_read_at' : 'requester_last_read_at';
        $ticket->forceFill([$column => now()])->save();
    }

    protected function serializeTicket(SupportTicket $ticket, $user): array
    {
        $payload = $ticket->toArray();
        $payload['has_unread_for_current'] = $this->hasUnreadForUser($ticket, $user);
        $payload['last_activity_at'] = optional($this->latestActivityAt($ticket))->toIso8601String();
        // toArray() snake_cases relation keys (submittedBy → submitted_by, which also
        // collides with the FK column). Re-expose them as the camelCase keys the frontend reads.
        $payload['submittedBy'] = $ticket->submittedBy?->toArray();
        $payload['assignedTo']  = $ticket->assignedTo?->toArray();
        return $payload;
    }

    protected function storeAttachments(Request $request, SupportTicket $ticket, ?SupportTicketMessage $message = null): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        foreach ((array) $request->file('attachments') as $file) {
            $path = $file->store('support-attachments', 'public');
            SupportTicketAttachment::create([
                'ticket_id'   => $ticket->id,
                'message_id'  => $message?->id,
                'file_path'   => $path,
                'file_name'   => $file->getClientOriginalName(),
            ]);
        }
    }

    public function index(Request $request)
    {
        $user  = $request->user();
        $query = SupportTicket::with(['submittedBy', 'assignedTo'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->priority, fn ($q) => $q->where('priority', $request->priority));

        // Non-admin users only see their own tickets
        if (! $this->canManageAllTickets($user)) {
            $query->where('submitted_by', $user->id);
        } else {
            $query->when($request->company, fn ($q) => $q->where('company', $request->company));
        }

        $page = $query->latest()->paginate(30);
        $page->setCollection(
            $page->getCollection()->map(fn (SupportTicket $ticket) => $this->serializeTicket($ticket, $user))
        );

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject'     => 'required|string|max:255',
            'description' => 'required|string',
            'priority'    => 'nullable|in:low,medium,high,urgent',
            'attachments'   => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $user   = $request->user();
        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-' . strtoupper(Str::random(8)),
            'company'       => $user->company,
            'submitted_by'  => $user->id,
            'subject'       => $data['subject'],
            'description'   => $data['description'],
            'priority'      => $data['priority'] ?? 'medium',
            'status'        => 'open',
            'requester_last_read_at' => now(),
            'admin_last_read_at' => null,
            'last_message_at' => now(),
            'last_message_sender_id' => $user->id,
        ]);

        $this->storeAttachments($request, $ticket);

        AuditLog::record('create', $ticket, null, $ticket->toArray());

        return response()->json($this->serializeTicket($ticket->fresh(['submittedBy', 'assignedTo', 'attachments']), $user), 201);
    }

    public function show(Request $request, SupportTicket $supportTicket)
    {
        $this->ensureTicketAccess($request, $supportTicket);
        $supportTicket->load(['submittedBy', 'assignedTo', 'attachments', 'messages.sender', 'messages.attachments']);
        $this->markAsRead($supportTicket, $request->user());
        return response()->json($this->serializeTicket($supportTicket->fresh(['submittedBy', 'assignedTo', 'attachments', 'messages.sender', 'messages.attachments']), $request->user()));
    }

    public function reply(Request $request, SupportTicket $supportTicket)
    {
        $this->ensureTicketAccess($request, $supportTicket);

        $data = $request->validate([
            'message'       => 'required|string',
            'attachments'   => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $message = SupportTicketMessage::create([
            'ticket_id'  => $supportTicket->id,
            'sender_id'  => $request->user()->id,
            'message'    => $data['message'],
        ]);

        $isInternal = $this->isInternalSupport($request->user());
        $supportTicket->update([
            'last_message_at' => $message->created_at ?? now(),
            'last_message_sender_id' => $request->user()->id,
            'admin_last_read_at' => $isInternal ? now() : $supportTicket->admin_last_read_at,
            'requester_last_read_at' => $isInternal ? $supportTicket->requester_last_read_at : now(),
        ]);

        $this->storeAttachments($request, $supportTicket, $message);

        return response()->json($message->load('attachments'), 201);
    }

    public function assign(Request $request, SupportTicket $supportTicket)
    {
        $data = $request->validate(['assigned_to' => 'required|exists:users,id']);
        $assignee = User::findOrFail($data['assigned_to']);
        abort_unless($assignee->company === 'telcovantage', 422, 'Assignee must be an internal TelcoVantage user.');

        $old = $supportTicket->toArray();
        $supportTicket->update(['assigned_to' => $assignee->id, 'status' => 'in_progress']);
        $message = SupportTicketMessage::create([
            'ticket_id' => $supportTicket->id,
            'sender_id' => $request->user()->id,
            'message' => 'Ticket assigned to internal support.',
        ]);
        $supportTicket->update([
            'last_message_at' => $message->created_at ?? now(),
            'last_message_sender_id' => $request->user()->id,
            'admin_last_read_at' => now(),
        ]);
        AuditLog::record('update', $supportTicket, $old, $supportTicket->toArray());

        return response()->json($supportTicket->fresh(['assignedTo']));
    }

    public function updateStatus(Request $request, SupportTicket $supportTicket)
    {
        $data = $request->validate(['status' => 'required|in:open,in_progress,resolved,closed']);

        $old = $supportTicket->toArray();
        $upd = ['status' => $data['status']];

        if ($data['status'] === 'resolved') $upd['resolved_at'] = now();
        if ($data['status'] === 'closed')   $upd['closed_at']   = now();

        $supportTicket->update($upd);

        if (in_array($data['status'], ['resolved', 'closed'], true)) {
            $supportTicket->sessions()
                ->where('status', 'active')
                ->whereNull('ended_at')
                ->update([
                    'status' => 'ended',
                    'ended_by' => $request->user()->id,
                    'ended_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        AuditLog::record('update', $supportTicket, $old, $supportTicket->toArray());

        return response()->json($supportTicket);
    }
}
