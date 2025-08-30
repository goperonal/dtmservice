<?php

namespace App\Filament\Resources\WhatsappInboxResource\Pages;

use App\Filament\Resources\WhatsappInboxResource;
use Filament\Resources\Pages\Page;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Forms;
use Livewire\WithFileUploads;
use App\Models\WhatsappWebhook;
use App\Services\WhatsAppService;

class ViewConversation extends Page implements HasForms
{
    use InteractsWithForms, WithFileUploads;

    protected static string $resource = WhatsappInboxResource::class;
    protected static string $view = 'filament.whatsapp.conversation';

    /** nomor kontak lawan bicara */
    public string $phone;

    /**
     * State form. Kita taruh semua field di sini supaya gampang diambil
     * dan menghindari type mismatch (string | array | TemporaryUploadedFile).
     */
    public array $data = [
        'text'    => null,
        'image'   => null, // string|array|\Livewire\...TemporaryUploadedFile|null
        'sticker' => null, // string|array|\Livewire\...TemporaryUploadedFile|null
    ];

    /** Deklarasi form (Filament v3) */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('text')
                    ->placeholder('Tulis pesan (emoji bebas)…')
                    ->extraAttributes(['class' => 'flex-1'])
                    ->columnSpan(8),

                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->multiple(false)        // ⬅️ pastikan single
                    ->imageEditor(false)
                    ->directory('wa_tmp')    // disimpan ke storage/app/wa_tmp
                    ->visibility('private')  // tidak butuh URL publik di sini
                    ->label('Image')
                    ->columnSpan(2),

                Forms\Components\FileUpload::make('sticker')
                    ->acceptedFileTypes(['image/webp'])
                    ->multiple(false)        // ⬅️ pastikan single
                    ->directory('wa_tmp')
                    ->visibility('private')
                    ->label('Sticker (webp)')
                    ->columnSpan(2),

                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('send')
                        ->label('Send')
                        ->submit('sendMessage')           // panggil method Livewire
                        ->keyBindings(['mod+enter'])
                        ->color('primary'),
                ])->columnSpanFull(),
            ])
            ->statePath('data'); // ⬅️ semua field ada di $this->data
    }

    public function mount(string $phone): void
    {
        $this->phone = $phone;

        // tandai terbaca semua pesan inbound pada thread ini
        if (method_exists(WhatsappWebhook::class, 'markConversationRead')) {
            WhatsappWebhook::markConversationRead($this->phone);
        }

        // Livewire v3: dispatch browser event
        $this->dispatch('chat-scrolldown'); // ditangkap Alpine di blade
    }

    /** Ambil isi chat untuk blade (pakai $this->messages) */
    public function getMessagesProperty(): array
    {
        $biz = (string) config('services.whatsapp.business_phone');

        $rows = WhatsappWebhook::query()
            ->where('event_type', 'message')
            ->where(function ($q) use ($biz) {
                $q->where(function ($qq) use ($biz) {
                    $qq->where('from_number', $this->phone)->where('to_number', $biz);
                })->orWhere(function ($qq) use ($biz) {
                    $qq->where('from_number', $biz)->where('to_number', $this->phone);
                });
            })
            ->orderBy('timestamp')
            ->get();

        return $rows->map(function (WhatsappWebhook $w) use ($biz) {
            $out     = $w->from_number === $biz;
            $payload = $w->payload_array;               // accessor robust di model
            $type    = $w->message_type
                ?: (is_array($payload) ? ($payload['type'] ?? null) : null)
                ?: 'text';

            $text = $w->text_body
                ?? ($payload['text']['body'] ?? null)
                ?? ($payload['image']['caption'] ?? null)
                ?? ($payload['document']['caption'] ?? null)
                ?? '';

            $mediaUrl = $w->media_local_url ?? null;    // sediakan accessor ini di model bila pakai download

            return [
                'out'  => $out,
                'type' => $type,
                'text' => (string) $text,
                'media'=> $mediaUrl,
                'time' => optional($w->timestamp)->timezone('Asia/Jakarta')->format('d M Y H:i'),
            ];
        })->all();
    }

    /** Kirim pesan: text, image, sticker (webp) */
    public function sendMessage(WhatsAppService $wa): void
    {
        $to      = $this->phone;
        $text    = $this->data['text']    ?? null;
        $images  = $this->data['image']   ?? null;   // string|array|TemporaryUploadedFile|null
        $stickers= $this->data['sticker'] ?? null;   // string|array|TemporaryUploadedFile|null

        // 1) text
        if (filled($text)) {
            $wa->sendText($to, $text);
        }

        // 2) image (bisa string path dari storage, atau TemporaryUploadedFile)
        foreach ($this->toUploadItems($images) as $item) {
            $mediaId = $item['type'] === 'uploaded'
                ? $wa->uploadMediaFromUploadedFile($item['file'])
                : $wa->uploadMedia($item['path'], null);

            $wa->sendMedia($to, $mediaId, 'image', $text ?: null);
        }

        // 3) sticker (webp) — tanpa caption
        foreach ($this->toUploadItems($stickers) as $item) {
            $mediaId = $item['type'] === 'uploaded'
                ? $wa->uploadMediaFromUploadedFile($item['file'])
                : $wa->uploadMedia($item['path'], 'image/webp');

            $wa->sendSticker($to, $mediaId);
        }

        // bersihkan form (reset state)
        $this->data = ['text' => null, 'image' => null, 'sticker' => null];
        $this->form->fill([]);

        // tandai inbound sebagai read (kalau kamu punya)
        if (method_exists(WhatsappWebhook::class, 'markConversationRead')) {
            WhatsappWebhook::markConversationRead($this->phone);
        }

        // scroll ke bawah
        $this->dispatch('chat-scrolldown');
    }

    /**
     * Normalisasi input FileUpload menjadi list item siap upload.
     * Return: array of
     *    ['type'=>'uploaded','file'=>TemporaryUploadedFile]  atau
     *    ['type'=>'path','path'=>string]   (contoh "wa_tmp/xxx.png")
     */
    private function toUploadItems($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value) ? $value : [$value];

        $out = [];
        foreach ($items as $v) {
            if ($v instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                $out[] = ['type' => 'uploaded', 'file' => $v];
            } elseif (is_string($v)) {
                // jika FileUpload sudah menyimpan ke storage (directory('wa_tmp')),
                // maka state akan string relatif "wa_tmp/xxxx.ext"
                $out[] = ['type' => 'path', 'path' => $v];
            }
        }
        return $out;
    }
}
