<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\BroadcastMessage;

class WhatsappWebhook extends Model
{
    protected $fillable = [
        'broadcast_id',
        'event_type',
        'message_id',
        'status',
        'from_number',
        'to_number',
        'conversation_id',
        'conversation_category',
        'pricing_model',
        'timestamp',
        'payload',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'payload' => 'array',
    ];

    public function broadcast()
    {
        return $this->belongsTo(BroadcastMessage::class);
    }
}
