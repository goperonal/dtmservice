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

    protected $appends = ['error_message'];

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
        return $this->hasOne(\App\Models\WhatsappWebhook::class, 'broadcast_id')->latestOfMany();
    }

    public function getErrorMessageAttribute(): ?string
    {
        if ($this->status !== 'failed') {
            return null;
        }

        $payload = $this->latestWebhook?->payload;
        if (! is_array($payload) && is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        $msg =
            data_get($payload, 'errors.0.message')
            ?? data_get($payload, 'errors.0.error_data.details')
            ?? data_get($payload, 'message')
            ?? data_get($payload, 'status_description');

        // fallback dari response_payload kalau ada
        if (! $msg) {
            $rp = $this->response_payload;
            if (! is_array($rp) && is_string($rp)) {
                $rp = json_decode($rp, true);
            }
            $msg =
                data_get($rp, 'error.message')
                ?? data_get($rp, 'messages.0.errors.0.details');
        }

        return $msg;
    }
}
