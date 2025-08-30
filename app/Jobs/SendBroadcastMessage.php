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
            $tmplName  = (string) ($campaign->template_name ?? '');
            $lang      = (string) ($campaign->template_language ?? 'id');
            $bindings  = is_array($campaign->variable_bindings ?? null) ? $campaign->variable_bindings : [];

            if (empty($to)) {
                $bm->update([
                    'status'           => 'failed',
                    'response_payload' => ['error' => 'Nomor recipient kosong'],
                ]);
                return;
            }

            if ($tmplName !== '' && !empty($bindings)) {
                $components = CampaignResource::buildWaBodyComponentsFromBindings($bindings, $bm->recipient);

                Log::info('WA Template Payload', [
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
                $body = (string) ($bm->body ?? '');
                if ($body === '') {
                    $bm->update([
                        'status'           => 'failed',
                        'response_payload' => ['error' => 'Template/bindings kosong dan tidak ada body fallback'],
                    ]);
                    return;
                }

                Log::info('WA Text Payload (fallback)', [
                    'broadcast_id' => $bm->id,
                    'to'           => $to,
                    'body'         => $body,
                ]);

                $response = $whatsapp->sendText($to, $body);
            }

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

            Log::warning('SendBroadcastMessage failed: '.$e->getMessage(), [
                'bm_id' => $bm->id ?? null,
            ]);

            throw $e;
        }
    }
}
