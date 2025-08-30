<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\WhatsappWebhook;

class WhatsAppService
{
    protected string $token;
    protected string $phoneId;
    protected string $url;
    protected string $businessNumber;

    public function __construct()
    {
        $this->token          = (string) config('services.whatsapp.token');
        $this->phoneId        = (string) config('services.whatsapp.phone_id');
        $this->url            = rtrim((string) config('services.whatsapp.url'), '/'); // ex: https://graph.facebook.com/v20.0
        $this->businessNumber = (string) config('services.whatsapp.business_phone');
    }

    /* =======================
       ======= KIRIM =========
       ======================= */

    public function sendWhatsAppTemplateByName(string $to, string $templateName, array $components, string $lang = 'id'): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'       => $to,
            'type'     => 'template',
            'template' => [
                'name'       => $templateName,
                'language'   => ['code' => $lang, 'policy' => 'deterministic'],
                'components' => $components,
            ],
        ];

        \Log::info('WA API Request (template)', compact('to', 'templateName', 'payload'));

        $res  = Http::withToken($this->token)->acceptJson()
            ->post("{$this->url}/{$this->phoneId}/messages", $payload);
        $res->throw();

        $resp = $res->json();
        \Log::info('WA API Response (template)', ['response' => $resp]);

        // ⬇️ Tambahkan preview text agar bubble tidak kosong di inbox
        $preview = $this->buildTemplatePreviewText($templateName, $components, $lang);

        $this->logOutbound(
            'template',
            $to,
            [
                'template' => [
                    'name'       => $templateName,
                    'language'   => $lang,
                    'components' => $components,
                ],
                // taruh juga "text.body" sebagai fallback universal di UI
                'text' => ['body' => $preview],
            ],
            data_get($resp, 'messages.0.id')
        );

        return $resp;
    }

    public function sendText(string $to, string $body): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'   => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ];

        \Log::info('WA API Request (text)', compact('to', 'payload'));

        $res  = Http::withToken($this->token)->acceptJson()
            ->post("{$this->url}/{$this->phoneId}/messages", $payload);
        $res->throw();

        $resp = $res->json();
        \Log::info('WA API Response (text)', ['response' => $resp]);

        $this->logOutbound('text', $to, $payload, data_get($resp, 'messages.0.id'));

        return $resp;
    }

    public function sendMedia(string $to, string $mediaId, string $type = 'image', ?string $caption = null): array
    {
        $body = array_filter([
            'id'      => $mediaId,
            'caption' => $type === 'sticker' ? null : $caption,
        ]);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'   => $to,
            'type' => $type,
            $type  => $body,
        ];

        \Log::info('WA API Request (media)', compact('to', 'type', 'payload'));

        $res  = Http::withToken($this->token)->acceptJson()
            ->post("{$this->url}/{$this->phoneId}/messages", $payload);
        $res->throw();

        $resp = $res->json();
        \Log::info('WA API Response (media)', ['response' => $resp]);

        $this->logOutbound($type, $to, $payload, data_get($resp, 'messages.0.id'));

        return $resp;
    }

    public function sendSticker(string $to, string $mediaId): array
    {
        return $this->sendMedia($to, $mediaId, 'sticker', null);
    }

    /* =======================
       ======= UPLOAD ========
       ======================= */

    public function uploadMedia(string $localPath, ?string $mime = null): string
    {
        $abs = $this->resolveLocalPath($localPath);
        if (!is_file($abs) || !is_readable($abs)) {
            throw new \RuntimeException("File tidak ditemukan / tidak bisa dibaca: {$abs}");
        }

        $mime ??= (mime_content_type($abs) ?: 'application/octet-stream');

        \Log::info('WA Upload (local path)', ['abs' => $abs, 'mime' => $mime]);

        $response = Http::withToken($this->token)
            ->attach('file', file_get_contents($abs), basename($abs))
            ->attach('messaging_product', 'whatsapp')
            ->attach('type', $mime)
            ->post("{$this->url}/{$this->phoneId}/media");

        $response->throw();
        $json = $response->json();

        \Log::info('WA Upload (response)', ['response' => $json]);

        return (string) data_get($json, 'id');
    }

    public function uploadMediaFromUploadedFile(UploadedFile $file): string
    {
        $real = $file->getRealPath();

        if (!$real || !is_file($real)) {
            $stored = $file->store('wa_tmp'); // storage/app/wa_tmp
            return $this->uploadMedia($stored, $file->getMimeType());
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        \Log::info('WA Upload (UploadedFile)', ['real' => $real, 'mime' => $mime]);

        $response = Http::withToken($this->token)
            ->attach('file', file_get_contents($real), $file->getClientOriginalName() ?: basename($real))
            ->attach('messaging_product', 'whatsapp')
            ->attach('type', $mime)
            ->post("{$this->url}/{$this->phoneId}/media");

        $response->throw();
        $json = $response->json();

        \Log::info('WA Upload (response)', ['response' => $json]);

        return (string) data_get($json, 'id');
    }

    public function uploadMediaFromUrl(string $url, ?string $preferredExt = null): string
    {
        Storage::disk('local')->makeDirectory('wa_tmp');

        \Log::info('WA Upload (download from URL)', ['url' => $url]);

        $resp = Http::get($url)->throw();
        $bin  = $resp->body();
        if ($bin === '' || $bin === null) {
            throw new \RuntimeException('Gagal mengunduh media dari URL.');
        }

        $extFromUrl = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
        $ext  = $preferredExt ?: ($extFromUrl ?: 'bin');
        $name = Str::ulid()->toBase32().'.'.$ext;
        $rel  = 'wa_tmp/'.$name;

        Storage::disk('local')->put($rel, $bin);
        $abs  = Storage::disk('local')->path($rel);
        $mime = mime_content_type($abs) ?: 'application/octet-stream';

        try {
            return $this->uploadMedia($abs, $mime);
        } finally {
            try { Storage::disk('local')->delete($rel); } catch (\Throwable $e) {}
        }
    }

    /* =======================
       ====== DOWNLOAD =======
       ======================= */

    /** Ambil info media (url, mime_type, file_size) dari Graph */
    public function getMediaInfo(string $mediaId): array
    {
        $resp = Http::withToken($this->token)
            ->get("{$this->url}/{$mediaId}")  // e.g. GET https://graph.facebook.com/v20.0/{media-id}
            ->throw()
            ->json();

        return is_array($resp) ? $resp : [];
    }

    /**
     * Unduh media ke disk "public" (folder wa_media) dan return URL publiknya.
     * Cache berdasarkan {mediaId}.{ext} — kalau sudah ada, tidak download lagi.
     */
    public function downloadMediaById(string $mediaId): string
    {
        $info = $this->getMediaInfo($mediaId);
        $url  = (string) ($info['url'] ?? '');
        $mime = (string) ($info['mime_type'] ?? 'application/octet-stream');

        if ($url === '') {
            throw new \RuntimeException("Media URL tidak ditemukan untuk ID {$mediaId}");
        }

        $ext = $this->extFromMime($mime);
        $disk = Storage::disk('public');
        $rel  = "wa_media/{$mediaId}.{$ext}";

        if ($disk->exists($rel)) {
            return $disk->url($rel);
        }

        // Unduh dengan Authorization
        $bin = Http::withToken($this->token)
            ->get($url)
            ->throw()
            ->body();

        if ($bin === '' || $bin === null) {
            throw new \RuntimeException('Unduhan media kosong.');
        }

        $disk->makeDirectory('wa_media');
        $disk->put($rel, $bin);

        return $disk->url($rel);
    }

    protected function extFromMime(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'video/mp4'  => 'mp4',
            'audio/ogg'  => 'ogg',
            'audio/mpeg' => 'mp3',
            'application/pdf' => 'pdf',
        ];
        return $map[strtolower($mime)] ?? 'bin';
    }

    /* =======================
       ====== UTILITIES ======
       ======================= */

    public function businessNumber(): string
    {
        return $this->businessNumber;
    }

    protected function resolveLocalPath(string $path): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Path kosong.');
        }

        if (preg_match('#^/|^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        if (preg_match('#^(?:private|public)/(\/.+)$#', $path, $m) === 1) {
            return $m[1];
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'public/')) {
            return Storage::disk('public')->path(substr($path, 7));
        }
        if (str_starts_with($path, 'private/')) {
            return Storage::disk('local')->path(substr($path, 8));
        }

        return Storage::disk('local')->path($path);
    }

    /**
     * Buat preview text untuk template (agar tampil di bubble & summary).
     */
    protected function buildTemplatePreviewText(string $name, array $components, string $lang): string
    {
        $vals = [];
        foreach ($components as $c) {
            if (strtolower((string)($c['type'] ?? '')) !== 'body') continue;
            foreach ((array)($c['parameters'] ?? []) as $p) {
                if (($p['type'] ?? '') === 'text') {
                    $vals[] = (string)($p['text'] ?? '');
                }
            }
        }
        $joined = trim(implode(' ', array_filter($vals)));
        $head   = "Template: {$name} ({$lang})";
        return $joined !== '' ? $head . "\n" . $joined : $head;
    }

    protected function logOutbound(string $type, string $to, array $payload, ?string $messageId): void
    {
        try {
            WhatsappWebhook::create([
                'event_type'  => 'message',
                'message_id'  => $messageId,
                'status'      => 'sent',
                'from_number' => $this->businessNumber,
                'to_number'   => $to,
                'timestamp'   => now(),
                'payload'     => json_encode(array_merge(['type' => $type], $payload), JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Gagal mencatat webhook outbound: '.$e->getMessage());
        }
    }

    public function downloadMediaToStorage(string $mediaId, string $disk = 'public', string $dir = 'wa_media'): array
    {
        // Step 1: get media URL & mime
        $meta = Http::withToken($this->token)
            ->acceptJson()
            ->get("{$this->url}/{$mediaId}")
            ->throw()
            ->json();

        $fileUrl = (string) data_get($meta, 'url');
        $mime    = (string) data_get($meta, 'mime_type', 'application/octet-stream');

        // Step 2: download binary (auth required)
        $bin = Http::withToken($this->token)->withHeaders([
            'Accept' => '*/*',
        ])->get($fileUrl)->throw()->body();

        // simpan ke disk
        $ext  = explode('/', $mime)[1] ?? 'bin';
        $name = \Illuminate\Support\Str::ulid()->toBase32().'.'.$ext;
        $path = trim($dir, '/').'/'.$name; // relative path di disk

        \Storage::disk($disk)->put($path, $bin);
        $abs  = \Storage::disk($disk)->path($path);
        $size = @filesize($abs) ?: strlen($bin);

        return [$path, $mime, $size];
    }
}
