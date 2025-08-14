<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Campaign;
use App\Models\WhatsAppTemplate;
use App\Models\Recipient;
use App\Models\WhatsappWebhook;

class BroadcastMessage extends Model
{
    protected $table = 'broadcasts';
    protected $fillable = [
        'campaign_id',
        'whatsapp_template_id',
        'recipient_id',
        'wamid',
        'status',
        'response_payload',
        'sent_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function template()
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_id');
    }

    public function recipient()
    {
        return $this->belongsTo(Recipient::class);
    }

    public function webhooks()
    {
        return $this->hasMany(WhatsappWebhook::class, 'broadcast_id', 'id');
    }

    public function latestWebhook()
    {
        return $this->hasOne(WhatsappWebhook::class, 'broadcast_id', 'id')
            ->latest('id');
    }
}
