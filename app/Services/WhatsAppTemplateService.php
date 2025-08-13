<?php

namespace App\Services;

use App\Models\WhatsAppTemplate;
use App\Models\WhatsAppTemplateSyncLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsAppTemplateService
{
    public function sync(): int
    {
        $url = rtrim(config('services.whatsapp.url'), '/')
            . '/' . config('services.whatsapp.business_id')
            . '/message_templates';

        $requestPayload = [
            'limit' => 100,
        ];

        $response = Http::timeout(20)
            ->withToken(config('services.whatsapp.token'))
            ->get($url, $requestPayload);

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
                $components = json_decode(json_encode($template['components']), true);
                $headerImageUrl = null;
        
                foreach ($components as $comp) {
                    if (
                        isset($comp['type']) &&
                        $comp['type'] === 'HEADER' &&
                        isset($comp['example']['header_handle'][0])
                    ) {
                        $headerImageUrl = $comp['example']['header_handle'][0];
                        break;
                    }
                }
        
                $localImagePath = null;
                Log::info('Header image URL: ' . $headerImageUrl);
                if ($headerImageUrl) {
                    try {
                        // Download image
                        $imageContent = Http::timeout(30)->get($headerImageUrl)->body();
                        $extension = pathinfo(parse_url($headerImageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                        $fileName = 'whatsapp_templates/' . uniqid('header_') . '.' . $extension;
        
                        // Simpan ke storage/public
                        Storage::disk('public')->put($fileName, $imageContent);
        
                        // URL publiknya (pastikan 'public' sudah di-link ke storage/app/public)
                        $localImagePath = Storage::url($fileName);
        
                        \Log::info("Header image saved: " . $localImagePath);
                    } catch (\Throwable $e) {
                        \Log::error("Failed to download header image: " . $e->getMessage());
                    }
                }
        
                WhatsAppTemplate::updateOrCreate(
                    ['name' => $template['name']],
                    [
                        'languages' => $template['language'] ?? null,
                        'status' => $template['status'] ?? null,
                        'category' => $template['category'] ?? null,
                        'components' => $components,
                        'parameter_format' => $template['parameter_format'] ?? null,
                        'header_image_url' => $localImagePath, // kolom baru untuk simpan link lokal
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('WhatsAppTemplate sync failed for template: ' . $template['name'] . ' - ' . $e->getMessage(), [
                    'template' => $template,
                ]);
            }
        }
        return count($templates);
    }

    public function pushTemplate(WhatsAppTemplate $template)
    {
        $url = rtrim(config('services.whatsapp.url'), '/') . '/' .
            config('services.whatsapp.business_id') . '/message_templates';

        // Ambil components langsung dari database
        $components = $template->components;

        $payload = [
            'name'             => $template->name,
            'category'         => $template->category,
            'parameter_format' => $template->parameter_format,
            'components'       => $components,
            'language'         => 'id',
        ];

        // Kirim ke WhatsApp API
        $response = Http::withToken(config('services.whatsapp.token'))
            ->post($url, $payload);

        if ($response->failed()) {
            throw new \Exception("Push template failed: " . $response->body());
        }

        return $response->json();
    }


    public function uploadMediaToWhatsApp(string $fileUrl): string
    {
        $phoneNumberId = config('services.whatsapp.phone_id');
        $baseUrl = rtrim(config('services.whatsapp.url'), '/');
        $token = config('services.whatsapp.token');

        // Ambil isi file dari URL
        $fileContents = file_get_contents($fileUrl);
        if ($fileContents === false) {
            throw new \Exception("Gagal mengambil file dari URL: $fileUrl");
        }

        // Deteksi nama file dari URL
        $filename = basename(parse_url($fileUrl, PHP_URL_PATH));

        // Buat URL endpoint upload
        $uploadUrl = "{$baseUrl}/{$phoneNumberId}/media";

        // Kirim permintaan POST ke API WhatsApp
        $response = Http::withToken($token)
            ->attach('file', $fileContents, $filename)
            ->post($uploadUrl, [
                'messaging_product' => 'whatsapp',
            ]);

        if ($response->failed()) {
            throw new \Exception("Gagal upload media ke WhatsApp: " . $response->body());
        }

        return $response->json('id');
    }

}
