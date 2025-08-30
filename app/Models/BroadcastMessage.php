<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Campaign;
use App\Models\WhatsAppTemplate;
use App\Models\Recipient;
use App\Models\WhatsappWebhook;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BroadcastMessage extends Model
{
    protected $table = 'broadcasts';

    protected $fillable = [
        'campaign_id',
        'whatsapp_template_id',
        'whatsapp_template_name', // <- opsional baru
        'recipient_id',
        'to',
        'body',
        'wamid',
        'status',
        'response_payload',
        'sent_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'sent_at'          => 'datetime',
    ];

    // Tambah 'wa_status' agar ikut terekspor bila perlu
    protected $appends = ['error_message', 'merged_status'];

    // === Relasi yang sudah ada ===
    public function campaign()     { return $this->belongsTo(\App\Models\Campaign::class); }
    public function template()     { return $this->belongsTo(\App\Models\WhatsAppTemplate::class, 'whatsapp_template_id'); }
    public function recipient()    { return $this->belongsTo(\App\Models\Recipient::class); }
    public function webhooks()     { return $this->hasMany(\App\Models\WhatsappWebhook::class, 'broadcast_id', 'id'); }

    // Terbaru by broadcast_id (fallback)
    public function latestWebhook()
    {
        return $this->hasOne(\App\Models\WhatsappWebhook::class, 'broadcast_id')->latestOfMany();
    }

    // ⬇️ Terbaru by WAMID (AKURAT) – cocokkan message_id (webhook) = wamid (broadcast)
    public function latestWebhookByWamid(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\WhatsappWebhook::class, 'message_id', 'wamid')->latestOfMany();
    }

    // === Accessors ===
    public function getErrorMessageAttribute(): ?string
    {
        if ($this->status !== 'failed') return null;

        $payload = $this->latestWebhookByWamid?->payload
            ?? $this->latestWebhook?->payload
            ?? null;

        if (!is_array($payload) && is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        $msg =
            data_get($payload, 'errors.0.message')
            ?? data_get($payload, 'errors.0.error_data.details')
            ?? data_get($payload, 'message')
            ?? data_get($payload, 'status_description');

        if (!$msg) {
            $rp = $this->response_payload;
            if (!is_array($rp) && is_string($rp)) $rp = json_decode($rp, true);
            $msg =
                data_get($rp, 'error.message')
                ?? data_get($rp, 'messages.0.errors.0.details');
        }

        return $msg ?: null;
    }

    // ⬇️ Ambil status WA terbaru dari webhook (prioritas by WAMID)
    public function getLastWebhookStatusAttribute(): ?string
    {
        $wh = $this->latestWebhookByWamid ?: $this->latestWebhook;
        if (! $wh) return null;

        $payload = $wh->payload;
        if (!is_array($payload)) {
            $decoded = json_decode($payload ?? '', true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        // Pola umum dari Meta:
        // statuses[0].status (delivered/read/failed), atau 'status' (custom), atau messages[0].status
        $status =
            data_get($payload, 'statuses.0.status') ??
            data_get($payload, 'status') ??
            data_get($payload, 'messages.0.status');

        return $status ? strtolower((string) $status) : null;
    }

    // ⬇️ Alias agar mudah dipakai di table/export sebagai 'wa_status'
    public function getWaStatusAttribute(): ?string
    {
        return $this->last_webhook_status;
    }

    public function getMergedStatusAttribute(): ?string
    {
        // Ambil WA status terbaru dari relasi (prefer by WAMID)
        $wh = $this->latestWebhookByWamid ?: $this->latestWebhook;

        $waStatus = null;
        if ($wh) {
            $payload = $wh->payload;
            if (! is_array($payload)) {
                $decoded = json_decode($payload ?? '', true);
                $payload = is_array($decoded) ? $decoded : [];
            }
            $waStatus =
                data_get($payload, 'statuses.0.status') ??
                data_get($payload, 'status') ??
                data_get($payload, 'messages.0.status');
            $waStatus = $waStatus ? strtolower((string) $waStatus) : null;
        }

        // Utamakan kegagalan
        if ($waStatus === 'failed' || $this->status === 'failed') {
            return 'failed';
        }

        // Kalau WA sudah delivered/read/sent, pakai itu
        if (in_array($waStatus, ['delivered', 'read', 'sent'], true)) {
            return $waStatus;
        }

        // fallback ke status queue
        return $this->status ?? $waStatus;
    }
}
