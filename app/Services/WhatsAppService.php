<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\WhatsappWebhook;

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
        $this->businessNumber = config('services.whatsapp.business_phone');
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

    public function sendText(string $to, string $body): array
    {
        $url = "{$this->url}/{$this->phoneId}/messages";
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'   => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ];

        $res = Http::withToken($this->token)->post($url, $payload);
        $res->throw();

        $resp = $res->json();

        // catat ke webhooks (outbound echo)
        WhatsappWebhook::create([
            'event_type' => 'message',
            'message_id' => data_get($resp, 'messages.0.id'),
            'status'     => 'sent',
            'from_number'=> $this->businessNumber,
            'to_number'  => $to,
            'timestamp'  => now(),
            'payload'    => json_encode(['from' => $this->businessNumber, 'to' => $to, 'type'=>'text', 'text'=>['body'=>$body]]),
        ]);

        return $resp;
    }
    /** Upload file ke WA Cloud API -> return media id */
    public function uploadMedia(string $localPath, string $mime): string
    {
        $url = "{$this->url}/{$this->phoneId}/media";

        $res = Http::asMultipart()
            ->withToken($this->token)
            ->post($url, [
                ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                ['name' => 'type',              'contents' => $mime],
                ['name' => 'file',              'contents' => fopen($localPath, 'r'), 'filename' => basename($localPath)],
            ]);

        $res->throw();
        return (string) data_get($res->json(), 'id');
    }

    /** Kirim image/doc by media id */
    public function sendMedia(string $to, string $mediaId, string $type = 'image', ?string $caption = null): array
    {
        $url = "{$this->url}/{$this->phoneId}/messages";

        $mediaKey = $type; // 'image' atau 'document'
        $payload  = [
            'messaging_product' => 'whatsapp',
            'to'   => $to,
            'type' => $type,
            $mediaKey => array_filter([
                'id'      => $mediaId,
                'caption' => $caption,
            ]),
        ];

        $res = Http::withToken($this->token)->post($url, $payload);
        $res->throw();

        $resp = $res->json();

        // echo ke webhooks table
        WhatsappWebhook::create([
            'event_type' => 'message',
            'message_id' => data_get($resp, 'messages.0.id'),
            'status'     => 'sent',
            'from_number'=> $this->businessNumber,
            'to_number'  => $to,
            'timestamp'  => now(),
            'payload'    => json_encode([
                'from' => $this->businessNumber, 'to'=>$to,
                'type' => $type, $type => ['id'=>$mediaId, 'caption'=>$caption]
            ]),
        ]);

        return $resp;
    }

    public function businessNumber(): string
    {
        return $this->businessNumber;
    }

}
