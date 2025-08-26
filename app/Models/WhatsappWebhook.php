<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\BroadcastMessage;

class WhatsappWebhook extends Model
{
     protected $fillable = [
        'broadcast_id','event_type','message_id','status',
        'from_number','to_number',
        'conversation_id','conversation_category','pricing_model',
        'timestamp','payload',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        // biarkan payload sebagai string di DB; kita sediakan accessor robust
    ];

    /**
     * Robust decode: payload bisa array normal, string JSON, atau JSON double-encoded.
     */
    public function getPayloadArrayAttribute(): array
    {
        $p = $this->attributes['payload'] ?? null;

        if (is_array($p)) return $p;

        if (is_string($p)) {
            // 1) coba decode langsung
            $j = json_decode($p, true);
            if (is_array($j)) return $j;

            // 2) coba hapus backslash (double-encoded)
            $j = json_decode(stripslashes($p), true);
            if (is_array($j)) return $j;

            // 3) coba trim tanda kutip luar
            $j = json_decode(trim($p, "\"'"), true);
            if (is_array($j)) return $j;
        }

        return [];
    }

    /** Ambil teks (jika ada) dari payload */
    public function getTextBodyAttribute(): ?string
    {
        $a = $this->payload_array;
        return $a['text']['body'] ?? null;
    }

    /** Apakah ini pesan dari bisnis ke kontak (outbound)? */
    public function isOutbound(string $biz): bool
    {
        return $this->from_number === $biz;
    }

    public function broadcast()
    {
        return $this->belongsTo(BroadcastMessage::class);
    }
}
