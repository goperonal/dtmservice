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
        'header'   => 'array',
        'body'     => 'array',
        'footer'   => 'array',
        'buttons'  => 'array',
        'languages' => 'array',
        'components' => 'array',
    ];

    public function getHeaderAttribute($value)
    {
        if ($value) return $value;
        $c = collect($this->components ?? [])->firstWhere('type', 'HEADER');
        if (! $c) return null;

        $format = strtoupper($c['format'] ?? '');
        if ($format === 'TEXT') {
            return ['type' => 'text', 'text' => $c['text'] ?? null];
        }
        if ($format === 'IMAGE') {
            // pakai kolom header_image_url yang sudah kamu isi saat create
            return ['type' => 'image', 'media_url' => $this->header_image_url];
        }
        return null;
    }

    public function getBodyAttribute($value)
    {
        if ($value) return $value;
        $c = collect($this->components ?? [])->firstWhere('type', 'BODY');
        return $c ? ['text' => $c['text'] ?? null] : null;
    }

    public function getFooterAttribute($value)
    {
        if ($value) return $value;
        $c = collect($this->components ?? [])->firstWhere('type', 'FOOTER');
        return $c ? ['text' => $c['text'] ?? null] : null;
    }

    public function getButtonsAttribute($value)
    {
        if (is_array($value)) return $value;
        $c = collect($this->components ?? [])->firstWhere('type', 'BUTTONS');
        if (! $c) return [];
        $buttons = $c['buttons'] ?? [];
        return array_map(function ($b) {
            return [
                'type' => strtolower($b['type'] ?? 'url'),
                'text' => $b['text'] ?? 'Open',
                'url'  => $b['url']  ?? '#',
            ];
        }, is_array($buttons) ? $buttons : []);
    }

}
