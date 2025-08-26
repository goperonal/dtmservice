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

    /**
     * Push template ke WhatsApp (Management API).
     * @throws \Exception on failure
     */
    public function pushTemplate(WhatsAppTemplate $template): array
    {
        $baseUrl = rtrim(config('services.whatsapp.url'), '/');         // ex: https://graph.facebook.com/v20.0
        $wabaId  = config('services.whatsapp.business_id');
        $token   = config('services.whatsapp.token');

        // Ambil komponen yang sudah kamu simpan saat create
        $components = $template->components;

        // WA minta language dalam string (contoh: 'id' atau 'en_US').
        // Kamu menyimpan di kolom "languages" (cast array). Kalau berupa array, ambil yang pertama.
        $language = is_array($template->languages) ? ($template->languages[0] ?? 'id') : ($template->languages ?: 'id');

        $payload = [
            'name'             => $template->name,                       // harus lowercase_underscore
            'category'         => strtoupper($template->category ?: 'MARKETING'),
            'parameter_format' => $template->parameter_format ?: 'POSITIONAL',
            'language'         => $language,
            'components'       => $components,
        ];

        $url = "{$baseUrl}/" . $wabaId . "/message_templates";

        $response = Http::timeout(30)
            ->withToken($token)
            ->post($url, $payload);

        if ($response->failed()) {
            throw new \Exception("Push template failed: " . $response->body());
        }

        // Optional: update status lokal ke "PENDING" setelah submit
        $template->update(['status' => 'PENDING']);

        return $response->json();
    }

    /**
     * Upload sample image untuk HEADER template (Resumable Upload).
     * Return: string "handle" (h) untuk dipakai sebagai header_handle.
     *
     * Docs/contour: POST /{app-id}/uploads -> dapat "upload:{id}" lalu
     * POST /upload:{id} (Authorization: OAuth <token>, header file_offset: 0, body = binary)
     * Respon: {"h":"..."} pakai ke example.header_handle = ["..."] saat create template.
     * (lihat contoh dan diskusi resmi/StackOverflow). 
     */
    public function uploadMediaToWhatsApp(string $localPath): string
    {
        $baseUrl   = rtrim(config('services.whatsapp.url'), '/');       // ex: https://graph.facebook.com/v20.0
        $appId     = config('services.whatsapp.app_id');                // APP ID
        $token     = config('services.whatsapp.token');

        if (!is_file($localPath)) {
            throw new \Exception("File tidak ditemukan: {$localPath}");
        }

        $fileName = basename($localPath);
        $fileSize = filesize($localPath) ?: 0;

        // Deteksi MIME supaya tidak hard-coded PNG
        $mime = mime_content_type($localPath) ?: 'image/jpeg';

        // --- Step 1: create upload session (POST) ---
        $sessionUrl = "{$baseUrl}/{$appId}/uploads?access_token={$token}";
        $sessionResp = Http::timeout(20)->post($sessionUrl, [
            'file_name'   => $fileName,
            'file_length' => $fileSize,
            'file_type'   => $mime,
        ]);

        if ($sessionResp->failed()) {
            throw new \Exception('Gagal memulai upload session: ' . $sessionResp->body());
        }

        $sessionId = (string) $sessionResp->json('id'); // ex: "upload:XXXX"
        if (! $sessionId) {
            throw new \Exception('Upload session id tidak ditemukan.');
        }

        $sessionId = str_starts_with($sessionId, 'upload:') ? substr($sessionId, 7) : $sessionId;

        // --- Step 2: upload binary ke /upload:{id} ---
        $binary = file_get_contents($localPath);
        if ($binary === false) {
            throw new \Exception("Gagal membaca file: {$localPath}");
        }

        $uploadUrl = "{$baseUrl}/upload:{$sessionId}";

        $uploadResp = Http::timeout(60)
            ->withHeaders([
                'Authorization' => "OAuth {$token}",
                'file_offset'   => 0,
                'Content-Type'  => $mime,
            ])
            ->withBody($binary, $mime)
            ->post($uploadUrl);

        if ($uploadResp->failed()) {
            throw new \Exception('Gagal upload file ke WhatsApp: ' . $uploadResp->body());
        }

        $handle = (string) $uploadResp->json('h');
        if (! $handle) {
            throw new \Exception('Response upload tidak berisi handle (h).');
        }

        return $handle;
    }
}
