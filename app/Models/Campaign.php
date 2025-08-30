<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\WhatsAppTemplate;
use App\Models\BroadcastMessage;
use App\Models\Recipient;

class Campaign extends Model
{
    protected $fillable = [
        'name',
        // id bisa tetap ada, tapi tidak wajib dipakai:
        'whatsapp_template_id',

        // — baru:
        'whatsapp_template_name',
        'template_name',
        'template_language',
        'variable_bindings',
        'template_components',
    ];

    protected $casts = [
        'variable_bindings'   => 'array',
        'template_components' => 'array',
    ];

    // Relasi lama (by id) — opsional tetap ada
    public function whatsappTemplate()
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_id');
    }

    // Relasi alternatif by name (kunci owner = name)
    public function whatsappTemplateByName()
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_name', 'name');
    }

    // Untuk tampilan tabel (fallback ke snapshot)
    public function getTemplateDisplayAttribute(): ?string
    {
        return $this->whatsappTemplateByName?->name
            ?? $this->template_name
            ?? $this->whatsapp_template_name;
    }

    public function broadcastMessages()
    {
        return $this->hasMany(BroadcastMessage::class);
    }
}