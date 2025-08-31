<?php

namespace App\Jobs;

use App\Models\BroadcastMessage;
use App\Services\WhatsAppService;
use App\Filament\Resources\CampaignResource;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\WhatsAppTemplate;

class SendBroadcastMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $tries = 1;
    public int $backoff = 0;
    public int $timeout = 120;

    public function __construct(public int $broadcastMessageId) {}

    public function viaQueue(): string
    {
        return 'broadcasts';
    }

    public function middleware(): array
    {
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
            $tmplName  = trim((string) ($campaign->template_name ?? $bm->whatsapp_template_name ?? ''));

            $tpl = $tmplName !== '' ? WhatsAppTemplate::where('name', $tmplName)->first() : null;

            // snapshot komponen hasil sync (disimpan di campaign)
            $componentsSnapshot = is_array($campaign->template_components ?? null)
                ? $campaign->template_components
                : [];

            $paramFormatDetected = CampaignResource::detectParameterFormatFromComponents(
                $componentsSnapshot,
                $tpl?->parameter_format ?? null
            );

            $lang     = (string) ($campaign->template_language ?: ($tpl?->languages ?: 'id'));
            $bindings = is_array($campaign->variable_bindings ?? null) ? $campaign->variable_bindings : [];

            // 0. context
            Log::info('[WA BRD] 0.context', [
                'bm_id'         => $bm->id,
                'campaign_id'   => $campaign->id,
                'to'            => $to,
                'template_name' => $tmplName,
                'template_param_format_db' => $tpl?->parameter_format,
                'detected_param_format'    => $paramFormatDetected,
                'language'      => $lang,
            ]);

            if (empty($to)) {
                $bm->update([
                    'status'           => 'failed',
                    'response_payload' => ['error' => 'Nomor recipient kosong'],
                ]);
                Log::warning('[WA BRD] 0a.no-recipient', ['bm_id' => $bm->id]);
                return;
            }

            // 24h guard
            $lastInbound = \App\Models\WhatsappWebhook::inbound()
                ->where('from_number', $to)
                ->orderByDesc('timestamp')
                ->first();

            $outside24h = !$lastInbound || now()->diffInHours(optional($lastInbound->timestamp)->copy() ?? now()->subYears(10)) > 24;

            if ($outside24h && $tmplName === '') {
                $bm->update([
                    'status'           => 'failed',
                    'response_payload' => [
                        'error'           => 'Outside 24h window – must send template',
                        'error_code'      => 131047,
                        'needs_template'  => true,
                    ],
                ]);

                Log::warning('[WA BRD] 0b.blocked-24h-no-template', [
                    'bm_id' => $bm->id,
                    'to'    => $to,
                ]);
                return;
            }

            // 1. inspect snapshot
            $inspect = $this->inspectTemplateSnapshot($componentsSnapshot);
            Log::info('[WA BRD] 1.inspect-snapshot', [
                'bm_id'                   => $bm->id,
                'placeholders_named_order'   => $inspect['named'] ?: [],
                'placeholders_numeric_order' => $inspect['numeric'] ?: [],
                'has_header_media'        => $inspect['header.has_media'],
                'header_format'           => $inspect['header.format'],
                'header_url_candidate'    => $inspect['header.url_candidate'],
                'buttons_summary'         => $inspect['buttons.summary'],
            ]);

            // 2. resolve bindings untuk recipient ini
            $resolved = $this->resolveBindingsForRecipient($bindings, $bm->recipient);
            Log::info('[WA BRD] 2.resolved-bindings', [
                'bm_id'         => $bm->id,
                'by_index'      => $resolved['by_index'],
                'by_name'       => $resolved['by_name'],
                'raw_bindings'  => $bindings,
            ]);

            // 3. HEADER via LINK (TANPA UPLOAD)  <<<— BAGIAN PENTING FIX
            $headerComps = [];
            if ($inspect['header.has_media']) {
                try {
                    $headerComps = CampaignResource::buildWaHeaderComponentFromSnapshotUsingLink(
                        $componentsSnapshot,
                        $tpl?->header_image_url ?: null
                    );
                    $finalLink = data_get($headerComps, '0.parameters.0.'.strtolower((string)$inspect['header.format']).'.link');
                    Log::info('[WA BRD] 3.header-built-link', [
                        'bm_id'       => $bm->id,
                        'type'        => strtolower((string)$inspect['header.format']),
                        'link'        => $finalLink,
                        'is_absolute' => preg_match('#^https?://#i', (string)$finalLink) ? true : false,
                    ]);
                } catch (\Throwable $e) {
                    $bm->update([
                        'status'           => 'failed',
                        'response_payload' => ['error' => 'HEADER media required but no URL available: '.$e->getMessage()],
                    ]);
                    Log::warning('[WA BRD] 3.header-link-failed', [
                        'bm_id'  => $bm->id,
                        'error'  => $e->getMessage(),
                    ]);
                    return;
                }
            } else {
                Log::info('[WA BRD] 3.header-skip', ['bm_id' => $bm->id]);
            }

            // 4. BODY components sesuai format (POSITIONAL/NAMED)
            $bodyComps = CampaignResource::buildWaBodyComponentsFromBindings(
                $bindings,
                $bm->recipient,
                $paramFormatDetected
            );
            $bodyPeek = [];
            foreach (($bodyComps[0]['parameters'] ?? []) as $p) {
                $bodyPeek[] = [
                    'type'           => $p['type'] ?? null,
                    'text_len'       => isset($p['text']) ? mb_strlen((string) $p['text']) : null,
                    'parameter_name' => $p['parameter_name'] ?? null,
                ];
            }
            Log::info('[WA BRD] 4.body-built', [
                'bm_id'                 => $bm->id,
                'parameter_format_used' => $paramFormatDetected,
                'parameters_peek'       => $bodyPeek,
            ]);

            // gabungkan components
            $components = [];
            if (!empty($headerComps)) $components = array_merge($components, $headerComps);
            if (!empty($bodyComps))   $components = array_merge($components, $bodyComps);

            // 5. kirim template
            if ($tmplName !== '') {
                $payloadPreview = [
                    'messaging_product' => 'whatsapp',
                    'to'       => $to,
                    'type'     => 'template',
                    'template' => [
                        'name'       => $tmplName,
                        'language'   => ['code' => $lang, 'policy' => 'deterministic'],
                        'components' => $components,
                    ],
                ];
                Log::info('[WA BRD] 5.payload-preview', [
                    'bm_id'   => $bm->id,
                    'payload' => $payloadPreview,
                ]);

                try {
                    $response = $whatsapp->sendWhatsAppTemplateByName(
                        to: $to,
                        templateName: $tmplName,
                        components: $components,
                        lang: $lang
                    );
                } catch (RequestException $e) {
                    $errJson = $e->response ? $e->response->json() : null;
                    Log::warning('[WA BRD] 5.send-error', [
                        'bm_id'     => $bm->id,
                        'status'    => $e->response?->status(),
                        'error_json'=> $errJson,
                        'message'   => $e->getMessage(),
                    ]);
                    throw $e;
                }
            } else {
                // fallback text (hanya jika dalam 24h window)
                $body = (string) ($bm->body ?? '');
                if ($body === '') {
                    $bm->update([
                        'status'           => 'failed',
                        'response_payload' => ['error' => 'Body text kosong & tidak ada template'],
                    ]);
                    Log::warning('[WA BRD] 5.no-body-no-template', ['bm_id' => $bm->id]);
                    return;
                }
                Log::info('[WA BRD] 5.text-payload-preview', [
                    'bm_id'   => $bm->id,
                    'to'      => $to,
                    'body_len'=> mb_strlen($body),
                ]);

                try {
                    $response = $whatsapp->sendText($to, $body);
                } catch (RequestException $e) {
                    $errJson = $e->response ? $e->response->json() : null;
                    Log::warning('[WA BRD] 5.text-send-error', [
                        'bm_id'     => $bm->id,
                        'status'    => $e->response?->status(),
                        'error_json'=> $errJson,
                        'message'   => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }

            // 6. handle response
            if (isset($response['error'])) {
                $bm->update([
                    'status'           => 'failed',
                    'response_payload' => $response,
                ]);
                Log::warning('[WA BRD] 6.graph-error', [
                    'bm_id'  => $bm->id,
                    'error'  => $response['error'],
                ]);
                return;
            }

            $bm->update([
                'status'           => 'sent',
                'sent_at'          => now(),
                'wamid'            => data_get($response, 'messages.0.id'),
                'response_payload' => $response,
            ]);

            Log::info('[WA BRD] 6.sent-ok', [
                'bm_id' => $bm->id,
                'wamid' => data_get($response, 'messages.0.id'),
            ]);

        } catch (\Throwable $e) {
            $bm->update([
                'status'           => 'failed',
                'response_payload' => ['error' => $e->getMessage()],
            ]);

            Log::warning('[WA BRD] 9.failed-exception', [
                'bm_id'   => $bm->id ?? null,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function inspectTemplateSnapshot(array $components): array
    {
        $bodyText = '';
        $header   = null;
        $buttons  = [];

        foreach ($components as $c) {
            if (!is_array($c)) continue;
            $type = strtoupper((string) ($c['type'] ?? ''));
            if ($type === 'BODY')     $bodyText = (string) ($c['text'] ?? '');
            elseif ($type === 'HEADER')   $header = $c;
            elseif ($type === 'BUTTONS')  $buttons = (array) ($c['buttons'] ?? []);
        }

        $flat = $this->normalizeText($bodyText);

        $named = [];
        if (preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', $flat, $m1)) {
            $named = array_values(array_unique($m1[1] ?? []));
        }

        $numeric = [];
        if (preg_match_all('/\{\{\s*(\d+)\s*\}\}/u', $flat, $m2)) {
            $numeric = array_map('intval', array_values(array_unique($m2[1] ?? [])));
            sort($numeric, SORT_NUMERIC);
        }

        $headerHasMedia = false;
        $headerFormat   = null;
        $headerUrlCand  = null;
        if (is_array($header)) {
            $fmt = strtoupper((string) ($header['format'] ?? 'TEXT'));
            if (in_array($fmt, ['IMAGE','VIDEO','DOCUMENT'], true)) {
                $headerHasMedia = true;
                $headerFormat   = $fmt;
                $headerUrlCand  = data_get($header, 'example.header_url.0')
                                ?: data_get($header, 'example.header_handle.0');
            }
        }

        $btnSummary = [];
        foreach ($buttons as $b) {
            $btnSummary[] = [
                'type' => $b['type'] ?? null,
                'text' => $b['text'] ?? null,
                'url'  => $b['url']  ?? null,
            ];
        }

        return [
            'named'                 => $named,
            'numeric'               => $numeric,
            'header.has_media'      => $headerHasMedia,
            'header.format'         => $headerFormat,
            'header.url_candidate'  => $headerUrlCand,
            'buttons.summary'       => $btnSummary,
        ];
    }

    protected function resolveBindingsForRecipient(array $bindings, \App\Models\Recipient $recipient): array
    {
        $byIndex = [];
        $byName  = [];

        foreach ($bindings as $b) {
            $field = $b['recipient_field'] ?? null;

            $val = '';
            if ($field) {
                if ($field === 'group') {
                    $val = $recipient->relationLoaded('groups')
                        ? $recipient->groups->pluck('name')->sort()->values()->join(', ')
                        : $recipient->groups()->pluck('name')->sort()->values()->join(', ');
                } else {
                    $val = (string) (data_get($recipient, $field) ?? '');
                }
            }

            if (isset($b['index'])) {
                $byIndex[(int) $b['index']] = $val;
            }
            if (!empty($b['name'])) {
                $byName[(string) $b['name']] = $val;
            }
        }

        ksort($byIndex, SORT_NUMERIC);

        return ['by_index' => $byIndex, 'by_name' => $byName];
    }

    protected function normalizeText(string $s): string
    {
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $map = [
            '｛' => '{', '｝' => '}', '﹛' => '{', '﹜' => '}',
            '０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4',
            '５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9',
        ];
        return strtr($s, $map);
    }
}
