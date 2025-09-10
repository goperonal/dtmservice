<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\BroadcastMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        // Ambil nilai RAW langsung dari DB, tidak lewat cast
        $raw = $this->getRawOriginal('payload');

        if (is_array($raw)) return $raw;
        if (is_object($raw)) return json_decode(json_encode($raw), true) ?: [];

        if (is_string($raw) && $raw !== '') {
            // Coba decode biasa
            $arr = json_decode($raw, true);
            if (is_array($arr)) return $arr;

            // Kalau gagal karena control chars, perbaiki lalu decode ulang
            $fixed = $this->jsonEscapeControlCharsInsideStrings($raw);
            $arr2  = json_decode($fixed, true);
            if (is_array($arr2)) return $arr2;

            // Coba varian strip slashes dan trim quotes setelah diperbaiki
            foreach ([
                stripslashes($fixed),
                trim($fixed, "\"'"),
                stripslashes(trim($fixed, "\"'")),
            ] as $cand) {
                $a = json_decode($cand, true);
                if (is_array($a)) return $a;
            }
        }

        return [];
    }

    /**
     * Escape hanya karakter kontrol di DALAM string JSON.
     * - Ubah \n menjadi \\n, \r menjadi \\r, dan kontrol < 0x20 menjadi \u00XX
     * - Abaikan bagian di luar string agar struktur JSON tetap valid.
     */
    private function jsonEscapeControlCharsInsideStrings(string $s): string
    {
        $out = '';
        $inString = false;
        $escaped  = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];

            if ($escaped) {
                // karakter setelah backslash tetap ditulis apa adanya
                $out .= $ch;
                $escaped = false;
                continue;
            }

            if ($ch === '\\') {
                $out .= '\\';
                $escaped = true;
                continue;
            }

            if ($ch === '"') {
                $out .= '"';
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                $ord = ord($ch);
                if ($ch === "\n") { $out .= '\\n'; continue; }
                if ($ch === "\r") { $out .= '\\r'; continue; }
                if ($ord < 0x20) {
                    $out .= sprintf('\\u%04x', $ord);
                    continue;
                }
            }

            $out .= $ch;
        }

        return $out;
    }

    /** === Teks/Caption untuk bubble. Template -> "Template: <nama>" === */
    public function getTextBodyAttribute(): ?string
    {
        $a = $this->payload_array;

        $v = data_get($a, 'text.body')
        ?? data_get($a, 'messages.0.text.body')
        ?? data_get($a, 'entry.0.changes.0.value.messages.0.text.body');
        if (is_string($v) || is_numeric($v)) return (string) $v;

        foreach ([
            'image.caption','video.caption','document.caption','audio.caption','sticker.caption',
            'messages.0.image.caption','messages.0.video.caption','messages.0.document.caption',
        ] as $path) {
            $cap = data_get($a, $path);
            if (is_string($cap) || is_numeric($cap)) return (string) $cap;
        }

        if ((data_get($a, 'type') === 'template') || (data_get($a, 'messages.0.type') === 'template')) {
            $name = data_get($a, 'template.name')
                ?? data_get($a, 'messages.0.template.name')
                ?? optional($this->broadcast)->template_name;
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
