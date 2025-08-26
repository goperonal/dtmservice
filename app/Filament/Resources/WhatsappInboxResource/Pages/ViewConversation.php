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

                // Pilih type by mime
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

    /** Ambil semua pesan 2 arah untuk nomor ini */
    protected function loadMessages(): void
    {
        $biz = app(WhatsAppService::class)->businessNumber();

        $rows = WhatsappWebhook::query()
            ->where('event_type', 'message')
            ->where(function ($q) use ($biz) {
                $q->where(function ($qq) { // inbound
                    $qq->where('from_number', $this->phone)
                       ->where('to_number',   app(WhatsAppService::class)->businessNumber());
                })->orWhere(function ($qq) use ($biz) { // outbound
                    $qq->where('from_number', $biz)
                       ->where('to_number',   $this->phone);
                });
            })
            ->orderBy('timestamp')
            ->get();

        $this->messages = $rows->map(function (WhatsappWebhook $w) use ($biz) {
            $p = $w->payload_array;
            $kind = $p['type'] ?? 'text';

            return [
                'id'        => $w->id,
                'at'        => optional($w->timestamp)->format('d M Y H:i:s'),
                'direction' => $w->isOutbound($biz) ? 'out' : 'in',
                'text'      => $p['text']['body'] ?? null,
                'imageId'   => $p['image']['id'] ?? null,
                'docId'     => $p['document']['id'] ?? null,
                'caption'   => $p[$kind]['caption'] ?? null,
                'type'      => $kind,
            ];
        })->all();
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
