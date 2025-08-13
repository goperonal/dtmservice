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
        'whatsapp_template_id',
    ];

    public function whatsappTemplate()
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_id');
    }

    public function recipients()
    {
        return $this->belongsToMany(Recipient::class, 'campaign_recipient', 'campaign_id', 'recipient_id')
            ->withTimestamps();
    }

    public function broadcastMessages()
    {
        return $this->hasMany(BroadcastMessage::class);
    }
}
