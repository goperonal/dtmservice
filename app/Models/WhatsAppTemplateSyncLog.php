<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplateSyncLog extends Model
{
    protected $table = 'whatsapp_template_sync_logs'; // pastikan sama dengan tabel di DB

    protected $fillable = [
        'user_id',
        'request_payload',
        'response_payload',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];
}
