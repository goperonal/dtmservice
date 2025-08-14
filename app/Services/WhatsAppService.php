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

    public function sendWhatsAppTemplate($recipientNumber, $template)
    {
        $components = [];

        foreach ($template->components as $component) {
            switch (strtoupper($component['type'])) {
                case 'HEADER':
                    if (!empty($component['format']) && $component['format'] === 'IMAGE') {
                        $imageLink = $template->header_image_url ?? null;

                        if ($imageLink) {
                            $components[] = [
                                "type" => "header",
                                "parameters" => [
                                    [
                                        "type" => "image",
                                        "image" => [
                                            "link" => rtrim(config('app.url'), '/') . $imageLink
                                        ]
                                    ]
                                ]
                            ];
                        }
                    }
                    break;

                    case 'BODY':
                        // Ambil dari example template jika ada
                        if (!empty($component['example']['body_text'][0])) {
                            $bodyParameters = [];
                            foreach ($component['example']['body_text'][0] as $param) {
                                $bodyParameters[] = [
                                    "type" => "text",
                                    "text" => $param
                                ];
                            }
                    
                            $components[] = [
                                "type" => "body",
                                "parameters" => $bodyParameters
                            ];
                        }
                        break;
            }
        }

        $payload = [
            "messaging_product" => "whatsapp",
            "to" => $recipientNumber,
            "type" => "template",
            "template" => [
                "name" => $template->name,
                "language" => [
                    "code" => "id"
                ],
                "components" => $components
            ]
        ];

        $response = Http::withToken($this->token)
            ->acceptJson()
            ->post("{$this->url}/{$this->phoneId}/messages", $payload);

        return $response->json();
    }

}
