<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\BroadcastMessage;

class WhatsappWebhook extends Model
{
    protected $fillable = [
        'broadcast_id','event_type','message_id','status',
        'from_number','to_number',
        'conversation_id','conversation_category','pricing_model',
        'timestamp','payload','read_at',
    ];

    protected $casts = [
        'timestamp'      => 'datetime',
        'read_at'        => 'datetime',
        'media_fetched'  => 'boolean',
    ];

    public function broadcast()
    {
        return $this->belongsTo(BroadcastMessage::class);
    }

    public function getPayloadArrayAttribute(): array
    {
        $p = $this->attributes['payload'] ?? null;
        if (is_array($p)) return $p;

        if (is_string($p)) {
            $j = json_decode($p, true);               if (is_array($j)) return $j;
            $j = json_decode(stripslashes($p), true); if (is_array($j)) return $j;
            $j = json_decode(trim($p, "\"'"), true);  if (is_array($j)) return $j;
        }
        return [];
    }

    /** === Teks/Caption untuk bubble. Template -> "Template: <nama>" === */
    public function getTextBodyAttribute(): ?string
    {
        $a = $this->payload_array;

        // teks biasa
        if ($t = data_get($a, 'text.body')) {
            return $t;
        }

        // caption media
        foreach (['image','video','document','audio','sticker'] as $k) {
            if ($cap = data_get($a, "{$k}.caption")) {
                return $cap;
            }
        }

        // template → TAMPILKAN HANYA NAMA
        if (($a['type'] ?? null) === 'template') {
            $name = data_get($a, 'template.name')
                ?: optional($this->broadcast)->template_name; // fallback dari campaign bila ada
            return $name ? "Template: {$name}" : 'Template';
        }

        return null;
    }

    public function getMessageTypeAttribute(): ?string
    {
        $a = $this->payload_array;
        if (isset($a['type'])) return strtolower((string) $a['type']);
        foreach (['text','image','sticker','video','audio','document','template'] as $k) {
            if (isset($a[$k])) return $k;
        }
        return null;
    }

    public function getMediaIdAttribute(): ?string
    {
        $type = $this->message_type;
        if (! in_array($type, ['image','sticker','video','audio','document'], true)) return null;
        return data_get($this->payload_array, "{$type}.id");
    }

    public function getMediaProxyUrlAttribute(): ?string
    {
        $id = $this->media_id;
        if (! $id) return null;
        try { return route('wa.media', ['mediaId' => $id]); }
        catch (\Throwable $e) { return url('/media/'.$id); }
    }

    /** === Ringkasan untuk list thread (kolom kiri) === */
    public function getSummaryAttribute(): string
    {
        $a = $this->payload_array;

        if ($t = data_get($a, 'text.body')) return $t;
        if ($c = data_get($a, 'image.caption')) return $c;
        if ($c = data_get($a, 'document.caption')) return $c;

        // template → TAMPILKAN HANYA NAMA
        if (($a['type'] ?? null) === 'template') {
            $name = data_get($a, 'template.name')
                ?: optional($this->broadcast)->template_name;
            return $name ? "Template: {$name}" : 'Template';
        }

        return strtoupper($this->message_type ?? 'TEXT');
    }

    public function scopeInbound(Builder $q): Builder
    {
        $biz = (string) config('services.whatsapp.business_phone');
        return $q->where('event_type', 'message')
                 ->where('to_number', $biz)
                 ->where('from_number', '<>', $biz);
    }

    public function scopeOutbound(Builder $q): Builder
    {
        $biz = (string) config('services.whatsapp.business_phone');
        return $q->where('event_type', 'message')
                 ->where('from_number', $biz);
    }

    public function scopeUnread(Builder $q): Builder
    {
        return $q->whereNull('read_at');
    }

    public function scopeForContact(Builder $q, string $phone): Builder
    {
        $biz = (string) config('services.whatsapp.business_phone');

        return $q->where('event_type', 'message')
                 ->where(function($qq) use ($phone, $biz) {
                     $qq->where(fn($w) => $w->where('from_number', $phone)->where('to_number', $biz))
                        ->orWhere(fn($w) => $w->where('from_number', $biz)->where('to_number', $phone));
                 });
    }

    public static function markConversationRead(string $contactPhone): void
    {
        $biz = (string) config('services.whatsapp.business_phone');

        static::where('event_type', 'message')
            ->whereNull('read_at')
            ->where('from_number', $contactPhone)
            ->where('to_number', $biz)
            ->update(['read_at' => now()]);
    }
}
