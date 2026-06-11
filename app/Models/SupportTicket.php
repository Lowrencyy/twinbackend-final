<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ticket_number', 'company', 'submitted_by', 'assigned_to',
        'subject', 'description', 'priority', 'status',
        'resolved_at', 'closed_at',
        'requester_last_read_at', 'admin_last_read_at',
        'last_message_at', 'last_message_sender_id',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at'   => 'datetime',
        'requester_last_read_at' => 'datetime',
        'admin_last_read_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function submittedBy() { return $this->belongsTo(User::class, 'submitted_by'); }
    public function assignedTo()  { return $this->belongsTo(User::class, 'assigned_to'); }
    public function messages()    { return $this->hasMany(SupportTicketMessage::class, 'ticket_id'); }
    public function attachments() { return $this->hasMany(SupportTicketAttachment::class, 'ticket_id'); }
    public function sessions()    { return $this->hasMany(SupportTicketSession::class, 'support_ticket_id'); }
    public function lastMessageSender() { return $this->belongsTo(User::class, 'last_message_sender_id'); }
}
