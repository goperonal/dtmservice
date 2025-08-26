<?php

namespace App\Filament\Resources\WhatsappInboxResource\Pages;

use App\Filament\Resources\WhatsappInboxResource;
use App\Models\WhatsappWebhook;
use App\Services\WhatsAppService;
use Filament\Resources\Pages\Page;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class ViewConversation extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string $resource = WhatsappInboxResource::class;
    protected static string $view = 'filament.whatsapp-chat';

    public string $phone;
    public array $messages = [];

    public ?string $text = null;
    public $attachment = null; // Livewire TemporaryUploadedFile

    public function mount(string $phone): void
    {
        $this->phone = $phone;
        $this->loadMessages();
    }

    public function getTitle(): string
    {
        return 'Chat';
    }

    public function getSubheading(): ?string
    {
        return $this->phone;
    }

    /** Form reply */
    protected function getFormSchema(): array
    {
        return [
            Grid::make()->schema([
                Textarea::make('text')
                    ->label('Reply')
                    ->rows(3)
                    ->placeholder('Tulis pesan...')
                    ->maxLength(4096),

                FileUpload::make('attachment')
                    ->label('Attachment (opsional)')
                    ->disk('public')
                    ->directory('wa-outgoing')
                    ->preserveFilenames()
                    ->imagePreviewHeight('100')
                    ->downloadable()
                    ->openable(),
            ]),
        ];
    }

    public function send(): void
    {
        $svc = app(WhatsAppService::class);

        try {
            if ($this->attachment) {
                $path = $this->attachment->store('wa-outgoing', 'public');
                $abs  = Storage::disk('public')->path($path);
                $mime = mime_content_type($abs) ?: 'application/octet-stream';

                $mediaId = $svc->uploadMedia($abs, $mime);

                // Tentukan type dari mime
                $type = str_starts_with($mime, 'image/') ? 'image' : 'document';
                $svc->sendMedia($this->phone, $mediaId, $type, $this->text ?: null);
            } elseif (filled($this->text)) {
                $svc->sendText($this->phone, $this->text);
            }

            $this->reset(['text', 'attachment']);
            Notification::make()->title('Terkirim')->success()->send();

            $this->loadMessages();
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
        }
    }

    /** Ambil semua pesan 2 arah untuk nomor ini + status terkini per outbound */
    public function loadMessages(): void
    {
        $biz = app(WhatsAppService::class)->businessNumber();

        // Ambil semua messages (inbound & outbound) untuk contact ini
        $rows = WhatsappWebhook::query()
            ->where('event_type', 'message')
            ->where(function ($root) use ($biz) {
                // inbound: from = contact, to = biz
                $root->where(function ($q) use ($biz) {
                    $q->where('from_number', $this->phone)
                      ->where('to_number',   $biz);
                })
                // outbound: from = biz, to = contact
                ->orWhere(function ($q) use ($biz) {
                    $q->where('from_number', $biz)
                      ->where('to_number',   $this->phone);
                });
            })
            ->orderByRaw('COALESCE(`timestamp`, `created_at`) ASC')
            ->orderBy('id', 'ASC')
            ->get();

        // Kumpulkan WAMID outbound (yg dari bisnis) untuk ambil status terbaru
        $outWamids = $rows->filter(fn($w) => $w->from_number === $biz)
            ->pluck('message_id')
            ->filter()
            ->values()
            ->all();

        $latestStatusByWamid = [];
        if (!empty($outWamids)) {
            $statusRows = WhatsappWebhook::query()
                ->where('event_type', 'status')
                ->whereIn('message_id', $outWamids)
                ->orderByRaw('COALESCE(`timestamp`, `created_at`) ASC')
                ->get()
                ->groupBy('message_id');

            foreach ($statusRows as $wamid => $group) {
                $last = $group->last();
                $latestStatusByWamid[$wamid] = [
                    'status' => $last->status, // sent/delivered/read (sesuai isi kolom mu)
                    'at'     => optional($last->timestamp)->format('d M Y H:i:s'),
                ];
            }
        }

        // Map ke array yang siap dipakai blade
        $this->messages = $rows->map(function (WhatsappWebhook $w) use ($biz, $latestStatusByWamid) {
            // decode payload aman
            $p = is_array($w->payload) ? $w->payload : (json_decode($w->payload, true) ?: json_decode(stripslashes((string) $w->payload), true) ?: []);

            $type = $p['type'] ?? (isset($p['image']) ? 'image' : (isset($p['document']) ? 'document' : 'text'));

            $direction = $w->from_number === $biz ? 'out' : 'in';
            $statusMeta = null;

            if ($direction === 'out' && $w->message_id) {
                $statusMeta = $latestStatusByWamid[$w->message_id] ?? null;

                // fallback kalau belum ada event status (pakai kolom status di baris message jika ada)
                if (!$statusMeta && $w->status) {
                    $statusMeta = [
                        'status' => $w->status,
                        'at'     => optional($w->timestamp)->format('d M Y H:i:s'),
                    ];
                }
            }

            return [
                'id'        => $w->id,
                'at'        => optional($w->timestamp)->format('d M Y H:i:s'),
                'direction' => $direction,
                'type'      => $type,
                'text'      => $p['text']['body'] ?? null,
                'imageId'   => $p['image']['id'] ?? null,
                'docId'     => $p['document']['id'] ?? null,
                'caption'   => $p[$type]['caption'] ?? null,
                'status'    => $statusMeta['status'] ?? null,    // sent / delivered / read
                'status_at' => $statusMeta['at'] ?? null,
            ];
        })->all();

        // trigger autoscroll di browser
        $this->dispatch('messages-updated');
    }

    protected function getForms(): array
    {
        return ['form'];
    }

    public function submit(): void
    {
        $this->send();
    }
}
