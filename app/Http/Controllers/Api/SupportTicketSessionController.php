<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class SupportTicketSessionController extends Controller
{
    protected const STALE_SINGLE_PARTICIPANT_MINUTES = 15;
    protected const STALE_IDLE_MINUTES = 90;

    protected function canManageAllTickets($user): bool
    {
        return in_array($user->role, ['admin', 'executive'], true)
            && $user->company === 'telcovantage';
    }

    protected function ensureTicketAccess(Request $request, SupportTicket $supportTicket): void
    {
        $user = $request->user();

        if ($this->canManageAllTickets($user)) {
            return;
        }

        abort_unless((int) $supportTicket->submitted_by === (int) $user->id, 403, 'You do not have access to this ticket.');
    }

    protected function ensureSessionAccess(Request $request, SupportTicketSession $session): void
    {
        $this->ensureTicketAccess($request, $session->ticket);
    }

    protected function dailyApiKey(): ?string
    {
        return env('DAILY_API_KEY');
    }

    protected function dailyBaseUrl(): string
    {
        return rtrim(env('DAILY_API_BASE', 'https://api.daily.co/v1'), '/');
    }

    protected function ensureDailyConfigured(): void
    {
        abort_if(!$this->dailyApiKey(), 503, 'Daily support sessions are not configured. Set DAILY_API_KEY first.');
    }

    protected function isInternalSupport($user): bool
    {
        return $this->canManageAllTickets($user);
    }

    protected function isStaleSession(SupportTicketSession $session): bool
    {
        if ($session->status !== 'active' || $session->ended_at) {
            return false;
        }

        $now = now();

        if ($session->started_at && $session->started_at->lt($now->copy()->subHours(4))) {
            return true;
        }

        $onlyOneSideJoined = (bool) ($session->admin_joined_at xor $session->requester_joined_at);
        if ($onlyOneSideJoined) {
            $pivot = $session->last_joined_at ?? $session->started_at;
            return $pivot ? $pivot->lt($now->copy()->subMinutes(self::STALE_SINGLE_PARTICIPANT_MINUTES)) : true;
        }

        if (! $session->admin_joined_at && ! $session->requester_joined_at) {
            return $session->started_at ? $session->started_at->lt($now->copy()->subMinutes(self::STALE_SINGLE_PARTICIPANT_MINUTES)) : true;
        }

        $pivot = $session->last_joined_at ?? $session->started_at;
        return $pivot ? $pivot->lt($now->copy()->subMinutes(self::STALE_IDLE_MINUTES)) : false;
    }

    protected function expireIfStale(?SupportTicketSession $session): ?SupportTicketSession
    {
        if (! $session || ! $this->isStaleSession($session)) {
            return $session;
        }

        $session->update([
            'status' => 'expired',
            'ended_at' => now(),
            'updated_at' => now(),
        ]);

        SupportTicketMessage::create([
            'ticket_id' => $session->support_ticket_id,
            'sender_id' => $session->started_by,
            'message' => 'Live support session expired due to inactivity.',
        ]);

        return $session->fresh();
    }

    protected function markJoin(SupportTicketSession $session, Request $request): SupportTicketSession
    {
        $user = $request->user();
        $isInternal = $this->isInternalSupport($user);
        $payload = ['last_joined_at' => now()];

        if ($isInternal) {
            $payload['admin_joined_at'] = now();
        } else {
            $payload['requester_joined_at'] = now();
        }

        $session->update($payload);

        return $session->fresh();
    }

    protected function dailyRequest(string $method, string $path, array $payload = []): array
    {
        $this->ensureDailyConfigured();

        $response = Http::withToken($this->dailyApiKey())
            ->acceptJson()
            ->send($method, $this->dailyBaseUrl() . $path, [
                'json' => $payload,
            ]);

        if ($response->failed()) {
            abort($response->status(), $response->json('info') ?: $response->json('error') ?: 'Daily API request failed.');
        }

        return $response->json();
    }

    protected function roomExpiry(): int
    {
        return now()->addHours(4)->timestamp;
    }

    protected function buildRoomPayload(SupportTicket $ticket): array
    {
        return [
            'name' => 'support-ticket-' . $ticket->id . '-' . Str::lower(Str::random(6)),
            'privacy' => 'private',
            'properties' => [
                'exp' => $this->roomExpiry(),
                'eject_at_room_exp' => true,
                'enable_chat' => true,
                'enable_screenshare' => true,
                'enable_prejoin_ui' => true,
                'start_video_off' => false,
                'start_audio_off' => false,
            ],
        ];
    }

    protected function createMeetingToken(SupportTicketSession $session, Request $request): string
    {
        $user = $request->user();
        $isInternal = $this->canManageAllTickets($user);

        $payload = [
            'properties' => [
                'room_name' => $session->room_name,
                'exp' => now()->addHours(2)->timestamp,
                'eject_at_token_exp' => true,
                'is_owner' => $isInternal,
                'user_name' => trim(($user->full_name ?? '') ?: (($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                'enable_screenshare' => true,
                'enable_prejoin_ui' => true,
                'start_video_off' => false,
                'start_audio_off' => false,
            ],
        ];

        $token = $this->dailyRequest('POST', '/meeting-tokens', $payload);

        return (string) ($token['token'] ?? '');
    }

    protected function serializeSession(SupportTicketSession $session, ?string $meetingToken = null): array
    {
        return [
            'id' => $session->id,
            'support_ticket_id' => $session->support_ticket_id,
            'provider' => $session->provider,
            'room_name' => $session->room_name,
            'room_url' => $session->room_url,
            'status' => $session->status,
            'started_at' => optional($session->started_at)->toIso8601String(),
            'ended_at' => optional($session->ended_at)->toIso8601String(),
            'launch_url' => $meetingToken ? ($session->room_url . '?t=' . urlencode($meetingToken)) : null,
            'meta' => $session->meta,
        ];
    }

    public function show(Request $request, SupportTicket $supportTicket)
    {
        $this->ensureTicketAccess($request, $supportTicket);

        $session = $supportTicket->sessions()
            ->latest('id')
            ->first();

        $session = $this->expireIfStale($session);

        if (!$session) {
            return response()->json(['session' => null]);
        }

        return response()->json(['session' => $this->serializeSession($session)]);
    }

    public function startOrJoin(Request $request, SupportTicket $supportTicket)
    {
        $this->ensureTicketAccess($request, $supportTicket);

        abort_if(in_array($supportTicket->status, ['closed', 'resolved'], true), 422, 'This ticket is already closed.');

        $session = $supportTicket->sessions()
            ->where('status', 'active')
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        $session = $this->expireIfStale($session);

        if (!$session) {
            $room = $this->dailyRequest('POST', '/rooms', $this->buildRoomPayload($supportTicket));

            $session = SupportTicketSession::create([
                'support_ticket_id' => $supportTicket->id,
                'provider' => 'daily',
                'room_name' => $room['name'],
                'room_url' => $room['url'],
                'status' => 'active',
                'started_by' => $request->user()->id,
                'started_at' => now(),
                'last_joined_at' => now(),
                'admin_joined_at' => $this->isInternalSupport($request->user()) ? now() : null,
                'requester_joined_at' => $this->isInternalSupport($request->user()) ? null : now(),
                'meta' => [
                    'daily_room' => $room,
                ],
            ]);

            SupportTicketMessage::create([
                'ticket_id' => $supportTicket->id,
                'sender_id' => $request->user()->id,
                'message' => 'Started a live support session.',
            ]);
        }

        $session = $this->markJoin($session, $request);

        $meetingToken = $this->createMeetingToken($session, $request);

        return response()->json([
            'session' => $this->serializeSession($session, $meetingToken),
        ]);
    }

    public function end(Request $request, SupportTicketSession $supportTicketSession)
    {
        $this->ensureSessionAccess($request, $supportTicketSession);

        if ($supportTicketSession->status !== 'active') {
            return response()->json([
                'session' => $this->serializeSession($supportTicketSession),
            ]);
        }

        $supportTicketSession->update([
            'status' => 'ended',
            'ended_by' => $request->user()->id,
            'ended_at' => now(),
        ]);

        SupportTicketMessage::create([
            'ticket_id' => $supportTicketSession->support_ticket_id,
            'sender_id' => $request->user()->id,
            'message' => 'Ended the live support session.',
        ]);

        return response()->json([
            'session' => $this->serializeSession($supportTicketSession->fresh()),
        ]);
    }
}
