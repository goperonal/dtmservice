<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Response;

class MediaProxyController extends Controller
{
    public function show(string $mediaId)
    {
        $token = (string) config('services.whatsapp.token');

        // Simpan metadata URL pendek biar gak sering call
        $info = Cache::remember("wa_media_info:$mediaId", 300, function () use ($mediaId, $token) {
            $meta = Http::withToken($token)->get("https://graph.facebook.com/v20.0/{$mediaId}");
            $meta->throw();
            return $meta->json();
        });

        $url = $info['url'] ?? null;
        if (! $url) {
            return response('Media URL not found', 404);
        }

        $bin = Http::withToken($token)->withOptions(['stream' => true])->get($url);
        if ($bin->failed()) {
            return response('Failed to fetch media', 500);
        }

        $contentType = $bin->header('Content-Type') ?? 'application/octet-stream';

        // (opsional) Cache-Control supaya browser simpan sebentar
        return new Response($bin->body(), 200, [
            'Content-Type'  => $contentType,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
