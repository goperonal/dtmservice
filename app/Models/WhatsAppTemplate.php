<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'name',
        'languages',
        'status',
        'category',
        'components',
        'parameter_format',
        'header_image_url',
    ];

    protected $casts = [
        'languages' => 'array',
        'components' => 'array',
    ];

    
}
