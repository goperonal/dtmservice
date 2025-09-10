<?php

namespace App\Filament\Resources\WhatsappInboxResource\Pages;

use App\Filament\Resources\WhatsappInboxResource;
use Filament\Resources\Pages\Page;
use Livewire\WithFileUploads;
use App\Models\WhatsappWebhook;
use App\Models\Recipient;
use App\Services\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Workspace extends Page
{
    use WithFileUploads;

    protected static string $resource = WhatsappInboxResource::class;
    protected static string $view = 'filament.whatsapp.workspace';

    /** ===== State ===== */
    public ?string $activePhone = null;
    public int $messagesVersion = 0;

    // composer
    public ?string $text = null;
    public $image = null;     // TemporaryUploadedFile
    public $document = null;  // TemporaryUploadedFile

    // modal konfirmasi file
    public bool $confirmSend = false;
    public ?string $pendingType = null;     // 'image'|'document'
    public ?string $pendingName = null;
    public ?string $pendingSize = null;     // "1.2 MB"
    public ?string $pendingPreview = null;  // data URL utk image

    // panel kanan
    public ?string $rec_name = null;
    public ?string $rec_notes = null;

    // explicit loading flags (stabil, tidak ikut wire:poll)
    public bool $isSending = false;
    public bool $isSaving  = false;

    /* ===================== Data providers ===================== */

    public function getThreadsProperty(): array
    {
        $biz = (string) config('services.whatsapp.business_phone');

        $rows = DB::table('whatsapp_webhooks as w')
            ->where('w.event_type', 'message')
            ->where(fn($q) => $q->where('w.from_number', $biz)->orWhere('w.to_number', $biz))
            ->selectRaw("CASE WHEN w.from_number = ? THEN w.to_number ELSE w.from_number END AS contact_phone", [$biz])
            ->selectRaw("MAX(w.updated_at) AS last_at")
            ->selectRaw("MAX(w.id) AS last_id")
            ->groupBy('contact_phone')
            ->having('contact_phone', '<>', $biz)
            ->orderByDesc('last_at')
            ->get();

        $threads = [];
        foreach ($rows as $r) {
            $contact = (string) $r->contact_phone;
            $last    = WhatsappWebhook::find($r->last_id);

            $unread = WhatsappWebhook::inbound()
                ->where('from_number', $contact)
                ->whereNull('read_at')
                ->count();

            $displayName = Recipient::where('phone', $contact)->value('name') ?: $contact;

            $threads[] = [
                'phone'        => $contact,
                'display_name' => $displayName,
                'summary'      => $last?->summary ?? '—',
                'last_at'      => $r->last_at
                    ? Carbon::parse($r->last_at)->timezone('Asia/Jakarta')->format('d M Y H:i')
                    : '',
                'unread'       => $unread,
            ];
        }

        return $threads;
    }

    public function getMessagesProperty(): array
    {
        if (!$this->activePhone) return [];

        $biz  = (string) config('services.whatsapp.business_phone');

        $rows = \App\Models\WhatsappWebhook::forContact($this->activePhone)
            ->where('event_type', 'message')
            ->orderBy('updated_at')
            ->get();

        $outboundIds = $rows->where('from_number', $biz)
            ->pluck('message_id')->filter()->unique()->values();

        $statusMap = collect();
        if ($outboundIds->isNotEmpty()) {
            $statusRows = \App\Models\WhatsappWebhook::query()
                ->where('event_type', 'status')
                ->whereIn('message_id', $outboundIds)
                ->get(['message_id','status','payload','id']);

            // failed > read > delivered > sent
            $prio = ['failed'=>99, 'read'=>3, 'delivered'=>2, 'sent'=>1];

            $statusMap = $statusRows->groupBy('message_id')->map(function ($g) use ($prio) {
                $best = null; $bestScore = -1;
                $errCode = null; $errMsg = null;

                foreach ($g as $row) {
                    $s = strtolower((string)$row->status);
                    $score = $prio[$s] ?? -1;

                    if ($s === 'failed') {
                        $p = json_decode((string)$row->payload, true) ?: [];
                        $e = $p['errors'][0] ?? [];
                        $errCode = $e['code'] ?? ($errCode ?? null);
                        $errMsg  = $e['error_data']['details'] ?? $e['message'] ?? ($errMsg ?? null);
                    }

                    if ($score > $bestScore) { $best = $s; $bestScore = $score; }
                }

                return ['status'=>$best, 'error_code'=>$errCode, 'error_message'=>$errMsg];
            });
        }

        $prio = ['failed'=>99, 'read'=>3, 'delivered'=>2, 'sent'=>1];

        return $rows->map(function (\App\Models\WhatsappWebhook $w) use ($biz, $statusMap, $prio) {
            $out   = $w->from_number === $biz;

            $baseStatus = strtolower((string)$w->status);
            $fromMap    = $out && $w->message_id ? ($statusMap->get($w->message_id) ?? null) : null;

            $final  = $baseStatus;
            $ecode  = null;
            $emsg   = null;

            if ($fromMap) {
                $mapStatus = strtolower((string)$fromMap['status']);
                if (($prio[$mapStatus] ?? -1) > ($prio[$baseStatus] ?? -1)) {
                    $final = $mapStatus;
                    $ecode = $fromMap['error_code'] ?? null;
                    $emsg  = $fromMap['error_message'] ?? null;
                }
            }

            return [
                'out'          => $out,
                'type'         => $w->message_type ?: 'text',
                'text'         => $w->text_body ?: '',
                'media'        => $w->media_proxy_url,
                'time'         => optional($w->updated_at)->timezone('Asia/Jakarta')->format('d M Y H:i'),
                'status'       => $final ?: null,
                'error_code'   => $ecode,
                'error_message'=> $emsg,
            ];
        })->all();
    }




    /* ===================== Lifecycle (file selected) ===================== */

    public function updatedImage($file): void
    {
        $this->preparePending('image', $file);
    }

    public function updatedDocument($file): void
    {
        $this->preparePending('document', $file);
    }

    protected function preparePending(string $type, $file): void
    {
        $this->pendingType = $type;
        $this->pendingName = method_exists($file, 'getClientOriginalName')
            ? $file->getClientOriginalName()
            : 'file';
        $size = method_exists($file, 'getSize') ? (int) $file->getSize() : 0;
        $this->pendingSize = $size ? $this->humanBytes($size) : null;

        // preview utk image (contain, tidak crop)
        $this->pendingPreview = null;
        if ($type === 'image' && is_object($file) && method_exists($file, 'temporaryUrl')) {
            try { $this->pendingPreview = $file->temporaryUrl(); } catch (\Throwable $e) {}
        }

        $this->confirmSend = true;
    }

    protected function humanBytes(int $b): string
    {
        $u = ['B','KB','MB','GB','TB']; $i=0;
        while ($b >= 1024 && $i < count($u)-1) { $b/=1024; $i++; }
        return number_format($b, $i ? 1 : 0).' '.$u[$i];
    }

    public function cancelPendingUpload(): void
    {
        // kosongkan file DULU, baru reset modal (hindari error 'after' undefined)
        $this->reset(['image', 'document']);

        $this->confirmSend    = false;
        $this->pendingType    = null;
        $this->pendingName    = null;
        $this->pendingSize    = null;
        $this->pendingPreview = null;
    }

    /* ===================== Actions ===================== */

    public function selectThread(string $phone): void
    {
        $this->activePhone = $phone;

        $rec = Recipient::where('phone', $phone)->first();
        $this->rec_name  = $rec?->name;
        $this->rec_notes = $rec?->notes;

        WhatsappWebhook::markConversationRead($phone);

        $this->messagesVersion++;
        $this->dispatch('chat-scrolldown');
    }

    /** tombol "Kirim" pada modal konfirmasi file */
    public function confirmAndSend(WhatsAppService $wa): void
    {
        $this->confirmSend = false;
        $this->sendMessage($wa);
    }

    /** tombol Send / Enter */
    public function sendMessage(WhatsAppService $wa): void
    {
        if (!$this->activePhone) return;

        // kalau ada file tapi belum confirm → biarkan modal yg jalan
        if (($this->image || $this->document) && $this->confirmSend) {
            return;
        }

        $to = $this->activePhone;
        $this->isSending = true;

        try {
            if ($this->document) {
                $mediaId = $wa->uploadMediaFromUploadedFile($this->document);
                $wa->sendMedia($to, $mediaId, 'document', $this->text ?: null);
            } elseif ($this->image) {
                $mediaId = $wa->uploadMediaFromUploadedFile($this->image);
                $wa->sendMedia($to, $mediaId, 'image', $this->text ?: null);
            } elseif (($this->text ?? '') !== '') {
                $wa->sendText($to, $this->text);
            } else {
                return;
            }

            $this->reset(['text', 'image', 'document']);
            $this->confirmSend = false;

            WhatsappWebhook::markConversationRead($this->activePhone);

            $this->messagesVersion++;
            $this->dispatch('chat-scrolldown');
        } catch (\Throwable $e) {
            Log::warning('Send failed: '.$e->getMessage());
        } finally {
            $this->isSending = false;
        }
    }

    public function saveRecipient(): void
    {
        if (!$this->activePhone) return;

        $this->isSaving = true;

        try {
            $rec = Recipient::firstOrNew(['phone' => $this->activePhone]);
            if ($this->rec_name !== null)  $rec->name  = $this->rec_name;
            if ($this->rec_notes !== null) $rec->notes = $this->rec_notes;
            $rec->save();
        } catch (\Throwable $e) {
            Log::warning('Save recipient failed: '.$e->getMessage());
        } finally {
            $this->isSaving = false;
        }
    }
}
