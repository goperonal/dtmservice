<?php

namespace App\Services;

use App\Models\WhatsAppTemplate;
use App\Models\WhatsAppTemplateSyncLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppTemplateService
{
    public function sync(): int
    {
        Log::info('Start sync WhatsApp templates');

        $url = rtrim(config('services.whatsapp.url'), '/')
            . '/' . config('services.whatsapp.business_id')
            . '/message_templates';

        $requestPayload = [
            'limit' => 100,
        ];

        $response = Http::timeout(20)
            ->withToken(config('services.whatsapp.token'))
            ->get($url, $requestPayload);

        Log::info('API call completed');

        // Simpan log request & response
        try {
            WhatsAppTemplateSyncLog::create([
                'user_id' => auth()->check() ? auth()->id() : null, // aman untuk null user
                'request_payload' => $requestPayload,
                // batasi ukuran data agar DB tidak overload
                'response_payload' => substr(json_encode($response->json()), 0, 65000),
            ]);
            Log::info('Sync log saved');
        } catch (\Throwable $e) {
            Log::error('Failed to store sync log: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new \Exception("Failed to fetch templates: " . $response->body());
        }

        $templates = $response->json('data') ?? [];

        foreach ($templates as $template) {
            try {
                WhatsAppTemplate::updateOrCreate(
                    ['name' => $template['name']],
                    [
                        'languages' => $template['language'] ?? null,
                        'status' => $template['status'] ?? null,
                        'category' => $template['category'] ?? null,
                        'components' => json_decode(json_encode($template['components']), true),
                        'parameter_format' => $template['parameter_format'] ?? null,
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('WhatsAppTemplate sync failed for template: ' . $template['name'] . ' - ' . $e->getMessage(), [
                    'template' => $template,
                ]);
            }
        }

        Log::info('Sync completed with count: ' . count($templates));
        return count($templates);
    }

}
