<?php

namespace App\Jobs;

use App\Models\BroadcastMessage;
use App\Services\WhatsAppService;
use App\Filament\Resources\CampaignResource;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBroadcastMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    // ⬇️ HAPUS properti $queue di sini (biarkan trait Queueable yang pegang)
    // public string $queue = 'broadcasts';

    public int $tries = 1;
    public int $backoff = 0;
    public int $timeout = 120;

    public function __construct(public int $broadcastMessageId) {}

    // Opsi A: set default queue untuk job ini
    public function viaQueue(): string
    {
        return 'broadcasts';
    }

    public function middleware(): array
    {
        // pastikan limiter 'whatsapp-sends' sudah dikonfigurasi (redis dll.)
        return [ new RateLimited('whatsapp-sends') ];
    }

    public function handle(WhatsAppService $whatsapp): void
    {
        if ($this->batch()?->cancelled()) return;

        $bm = BroadcastMessage::with(['campaign', 'recipient.groups'])
            ->find($this->broadcastMessageId);

        if (! $bm || $bm->status !== 'pending') return;

        $bm->update(['status' => 'processing']);

        try {
            $to        = $bm->recipient?->phone;
            $campaign  = $bm->campaign;

            // ⬅️ Ambil template name dari campaign ATAU dari kolom broadcast
            $tmplName  = trim((string) ($campaign->template_name ?? $bm->whatsapp_template_name ?? ''));
            $lang      = (string) ($campaign->template_language ?? 'id');

            // Binding variabel (boleh kosong; kita tetap coba kirim template jika ada tmplName)
            $bindings  = is_array($campaign->variable_bindings ?? null) ? $campaign->variable_bindings : [];

            if (empty($to)) {
                $bm->update([
                    'status'           => 'failed',
                    'response_payload' => ['error' => 'Nomor recipient kosong'],
                ]);
                return;
            }

            // ===== 24-hour window guard =====
            // kalau last inbound > 24 jam dan TIDAK ada template_name -> jangan kirim text
            $lastInbound = \App\Models\WhatsappWebhook::inbound()
                ->where('from_number', $to)
                ->orderByDesc('timestamp')
                ->first();

            $outside24h = !$lastInbound || now()->diffInHours(optional($lastInbound->timestamp)->copy() ?? now()->subYears(10)) > 24;

            if ($outside24h && $tmplName === '') {
                // Jangan fallback ke teks — tandai perlu template
                $bm->update([
                    'status'           => 'failed',
                    'response_payload' => [
                        'error'           => 'Outside 24h window – must send template',
                        'error_code'      => 131047,
                        'needs_template'  => true,
                    ],
                ]);

                \Log::warning('Broadcast blocked by 24h window (no template)', [
                    'bm_id' => $bm->id, 'to' => $to,
                ]);
                return;
            }

            // ===== Kirim =====
            if ($tmplName !== '') {
                // Rakitan komponen dari bindings (jika ada). Biarkan kosong jika tidak ada parameter.
                $components = \App\Filament\Resources\CampaignResource::buildWaBodyComponentsFromBindings(
                    $bindings, $bm->recipient
                );

                \Log::info('WA Template Payload (broadcast)', [
                    'broadcast_id' => $bm->id,
                    'to'           => $to,
                    'template'     => $tmplName,
                    'language'     => $lang,
                    'components'   => $components,
                ]);

                $response = $whatsapp->sendWhatsAppTemplateByName(
                    to: $to,
                    templateName: $tmplName,
                    components: $components,
                    lang: $lang
                );
            } else {
                // Hanya masuk ke sini jika masih dalam 24 jam
                $body = (string) ($bm->body ?? '');
                if ($body === '') {
                    $bm->update([
                        'status'           => 'failed',
                        'response_payload' => ['error' => 'Body text kosong & tidak ada template'],
                    ]);
                    return;
                }

                \Log::info('WA Text Payload (broadcast)', [
                    'broadcast_id' => $bm->id,
                    'to'           => $to,
                    'body'         => $body,
                ]);

                $response = $whatsapp->sendText($to, $body);
            }

            // ===== Handle response =====
            if (isset($response['error'])) {
                $bm->update([
                    'status'           => 'failed',
                    'response_payload' => $response,
                ]);
                return;
            }

            $bm->update([
                'status'           => 'sent',
                'sent_at'          => now(),
                'wamid'            => data_get($response, 'messages.0.id'),
                'response_payload' => $response,
            ]);

        } catch (\Throwable $e) {
            $bm->update([
                'status'           => 'failed',
                'response_payload' => ['error' => $e->getMessage()],
            ]);

            \Log::warning('SendBroadcastMessage failed: '.$e->getMessage(), [
                'bm_id' => $bm->id ?? null,
            ]);

            throw $e;
        }
    }

}
