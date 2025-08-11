<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsAppService
{
    protected $token;
    protected $phoneId;
    protected $url;

    public function __construct()
    {
        $this->token = config('services.whatsapp.token');
        $this->phoneId = config('services.whatsapp.phone_id');
        $this->url = config('services.whatsapp.url');
    }

    public function sendTemplate($to, $templateName, $variables = [], $category = 'marketing')
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => 'id'], // ubah jadi en_US kalau default template Meta
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => collect($variables)->map(fn($val) => [
                            'type' => 'text',
                            'text' => $val
                        ])->values()->toArray()
                    ]
                ]
            ]
        ];

        $response = Http::withToken($this->token)
            ->post("{$this->url}/{$this->phoneId}/messages", $payload);

        return $response->json();
    }
}