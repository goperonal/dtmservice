<?php

namespace App\Jobs;

use App\Models\BroadcastMessage;
use App\Services\WhatsAppService;
use Illuminate\Bus\Batchable;          // ⬅️ tambahkan
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBroadcastMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;  // ⬅️ tambahkan Batchable

    public int $tries = 1;
    public int $backoff = 0;
    public $timeout = 120;

    public function __construct(public int $broadcastMessageId) {}

    public function middleware(): array
    {
        return [ new RateLimited('whatsapp-sends') ];
    }

    public function handle(WhatsAppService $whatsapp): void
    {
        $bm = BroadcastMessage::with(['campaign.whatsappTemplate', 'recipient'])
            ->find($this->broadcastMessageId);

        if (! $bm || $bm->status !== 'pending') return;

        // Hentikan jika batch dibatalkan (opsional, tapi bagus ada)
        if ($this->batch()?->cancelled()) return;

        $bm->update(['status' => 'processing']);

        try {
            $template = $bm->campaign?->whatsappTemplate;
            $to       = $bm->recipient?->phone;

            if (! $template || ! $to) {
                $bm->update([
                    'status' => 'failed',
                    'response_payload' => ['error' => 'Template atau nomor tidak ditemukan'],
                ]);
                return;
            }

            $response = $whatsapp->sendWhatsAppTemplate($to, $template);

            if (isset($response['error'])) {
                $bm->update([
                    'status' => 'failed',
                    'response_payload' => $response,
                ]);
                return;
            }

            $bm->update([
                'status'           => 'sent',
                'sent_at'          => now(),
                'wamid'            => $response['messages'][0]['id'] ?? null,
                'response_payload' => $response,
            ]);

        } catch (\Throwable $e) {
            $bm->update([
                'status' => 'failed',
                'response_payload' => ['error' => $e->getMessage()],
            ]);
            Log::warning('SendBroadcastMessage failed: '.$e->getMessage(), ['bm_id' => $bm->id ?? null]);
            return;
        }
    }
}
