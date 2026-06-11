<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SupportTicketAttachment extends Model
{
    protected $fillable = ['ticket_id', 'message_id', 'file_path', 'file_name'];
    protected $appends = ['file_url'];

    public function ticket()  { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function message() { return $this->belongsTo(SupportTicketMessage::class); }

    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path ? Storage::disk('public')->url($this->file_path) : null;
    }
}
