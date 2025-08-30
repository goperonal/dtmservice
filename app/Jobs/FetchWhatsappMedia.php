<?php

namespace App\Jobs;

use App\Models\WhatsappWebhook;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

class FetchWhatsappMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $webhookId) {}

    public function handle(WhatsAppService $wa): void
    {
        $w = WhatsappWebhook::find($this->webhookId);
        if (!$w || $w->media_fetched || !$w->media_id) return;

        [$relative, $mime, $size] = $wa->downloadMediaToStorage(
            mediaId: $w->media_id,
            disk: 'public',
            dir: 'wa_media' // => storage/app/public/wa_media
        );

        $w->update([
            'media_path'   => $relative,
            'media_mime'   => $mime,
            'media_size'   => $size,
            'media_fetched'=> true,
        ]);
    }
}
