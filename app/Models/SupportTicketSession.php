<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketSession extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'provider',
        'room_name',
        'room_url',
        'status',
        'started_by',
        'ended_by',
        'started_at',
        'last_joined_at',
        'admin_joined_at',
        'requester_joined_at',
        'ended_at',
        'meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_joined_at' => 'datetime',
        'admin_joined_at' => 'datetime',
        'requester_joined_at' => 'datetime',
        'ended_at' => 'datetime',
        'meta' => 'array',
    ];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function startedBy()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
