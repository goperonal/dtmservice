<?php

namespace App\Filament\Resources\WhatsappInboxResource\Pages;

use App\Filament\Resources\WhatsappInboxResource;
use App\Models\WhatsappWebhook;
use App\Models\Recipient;
use App\Services\WhatsAppService;
use Filament\Forms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Filament\Notifications\Notification;

class ViewConversation extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = WhatsappInboxResource::class;
    protected static string $view = 'filament.whatsapp.conversation';

    public ?string $phone = null;        // lawan bicara
    public string  $businessPhone;       // nomor bisnis
    public array   $messages = [];       // isi chat
    public ?string $message = null;      // input balasan
    public ?string $contactName = null;  // nama recipient (jika ada)

    public function mount(?string $phone = null): void
    {
        if (empty($phone)) {
            $this->redirect(WhatsappInboxResource::getUrl('index'));
            return;
        }

        $this->phone = $phone;
        $this->businessPhone = (string) config('services.whatsapp.business_phone', '');
        $this->contactName = \App\Models\Recipient::where('phone', $this->phone)->value('name');

        $this->loadMessages();
    }

    public function getHeading(): string|Htmlable
    {
        return $this->contactName ?: $this->phone;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->contactName ? $this->phone : null;
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Textarea::make('message')
                ->label('Reply')
                ->rows(2)
                ->placeholder('Tulis pesan...')
                ->required()
                ->maxLength(4096)
                ->columnSpanFull(),
            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('send')
                    ->label('Send')
                    ->color('primary')
                    ->action('sendMessage'),
            ])->alignEnd(),
        ];
    }

    public function sendMessage(WhatsAppService $wa): void
    {
        $text = trim((string) $this->message);
        if ($text === '') return;

        $resp  = $wa->sendText($this->phone, $text);
        $wamid = data_get($resp, 'messages.0.id');

        // log outbound supaya tampil di chat
        WhatsappWebhook::create([
            'event_type' => 'message',
            'message_id' => $wamid,
            'status'     => 'sent',
            'from_number'=> $this->businessPhone,
            'to_number'  => $this->phone,
            'timestamp'  => now('UTC'),
            'payload'    => [
                'from'      => $this->businessPhone,
                'to'        => $this->phone,
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => $text],
            ],
        ]);

        $this->message = null;
        $this->loadMessages();
        $this->dispatch('chat-scrolldown');
    }

    protected function loadMessages(): void
    {
        $bp = $this->businessPhone;
        $ph = $this->phone;

        $rows = WhatsappWebhook::query()
            ->where('event_type', 'message')
            ->where(function ($q) use ($bp, $ph) {
                $q->where('from_number', $ph)->where('to_number', $bp);   // inbound
            })
            ->orWhere(function ($q) use ($bp, $ph) {
                $q->where('from_number', $bp)->where('to_number', $ph);   // outbound
            })
            ->orderBy('id', 'asc')
            ->limit(1000)
            ->get();

        $this->messages = $rows->map(function ($r) use ($bp) {
            // waktu dari kolom timestamp (sudah cast datetime) atau created_at
            $dt = $r->timestamp ?: $r->created_at;
            $time = optional($dt)->timezone('Asia/Jakarta')?->format('d M Y H:i') . ' WIB';

            $p = is_array($r->payload) ? $r->payload : json_decode((string) $r->payload, true);
            $type = is_array($p) ? ($p['type'] ?? null) : null;
            $text = is_array($p) ? data_get($p, 'text.body') : null;

            return [
                'id'   => $r->id,
                'out'  => $r->from_number === $bp, // kanan bila kita yang kirim
                'time' => $time,
                'type' => $type,
                'text' => $text ?: strtoupper((string)$type ?: '-'),
            ];
        })->toArray();
    }
}
