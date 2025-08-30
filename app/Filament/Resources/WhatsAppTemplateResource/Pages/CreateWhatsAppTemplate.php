<?php

namespace App\Filament\Resources\WhatsAppTemplateResource\Pages;

use App\Filament\Resources\WhatsAppTemplateResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use App\Services\WhatsAppTemplateService;
use Illuminate\Support\Facades\Storage;
use Filament\Actions;


class CreateWhatsAppTemplate extends CreateRecord
{
    protected static string $resource = WhatsAppTemplateResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

     // Hilangkan "Create & create another"
    protected function getFormActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Create')
                ->createAnother(false),   // <-- ini yang mematikan tombol kedua
            // Tombol Cancel pakai generic Action
            Actions\Action::make('cancel')
                ->label('Cancel')
                ->url($this->getResource()::getUrl('index'))   // kembali ke list
                ->color('gray'),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $components = [];

        // ======================
        // HEADER (image / text)
        // ======================
        $headerType  = data_get($data, 'header.type');
        $headerText  = data_get($data, 'header.text');
        $mediaState  = data_get($data, 'header.media_url'); // bisa string | TemporaryUploadedFile | array | null

        if ($headerType && $headerType !== 'none') {
            if ($headerType === 'image') {
                $storedPath = null;

                // 1) Normalisasi & simpan file ke disk 'public' bila belum tersimpan
                if ($mediaState instanceof TemporaryUploadedFile) {
                    $storedPath = $mediaState->store('whatsapp-templates/headers', 'public');
                } elseif (is_string($mediaState) && $mediaState !== '') {
                    // bisa jadi sudah berupa path relatif "whatsapp-templates/headers/xxx.png"
                    $storedPath = $mediaState;
                } elseif (is_array($mediaState)) {
                    // beberapa versi bisa mengirim array { uuid: {...} } atau [TemporaryUploadedFile, ...]
                    $candidate = Arr::get($mediaState, 'path')
                        ?? Arr::get($mediaState, '0.path')
                        ?? (is_object($mediaState[0] ?? null) ? $mediaState[0] : null);

                    if ($candidate instanceof TemporaryUploadedFile) {
                        $storedPath = $candidate->store('whatsapp-templates/headers', 'public');
                    } elseif (is_string($candidate) && $candidate !== '') {
                        $storedPath = $candidate;
                    }
                }

                $headerHandle = null;
                if ($storedPath) {
                    // absolute path untuk upload ke Meta
                    $absPath = Storage::disk('public')->path($storedPath);

                    try {
                        $headerHandle = app(\App\Services\WhatsAppTemplateService::class)
                            ->uploadMediaToWhatsApp($absPath);
                    } catch (\Throwable $e) {
                        \Log::error('uploadMediaToWhatsApp failed: ' . $e->getMessage());
                    }

                    // simpan URL publik lokal untuk preview di list
                    $data['header_image_url'] = Storage::url($storedPath);
                }

                // Bentuk komponen HEADER (IMAGE)
                $headerComp = [
                    'type'   => 'HEADER',
                    'format' => 'IMAGE',
                ];
                // Sertakan example header_handle bila upload sukses (recommended oleh Meta)
                if ($headerHandle) {
                    $headerComp['example'] = ['header_handle' => [$headerHandle]];
                }
                $components[] = $headerComp;
            }

            if ($headerType === 'text' && !empty($headerText)) {
                $components[] = [
                    'type'   => 'HEADER',
                    'format' => 'TEXT',
                    'text'   => $headerText,
                ];
            }
        }

        // =====
        // BODY
        // =====
        if (!empty($data['body']['text'])) {
            preg_match_all('/\{\{(\d+)\}\}/', $data['body']['text'], $matches);
            $paramCount    = count($matches[1]);
            $exampleValues = !empty($data['body']['example'])
                ? array_map('trim', explode(',', $data['body']['example']))
                : [];
            $exampleValues = array_pad($exampleValues, $paramCount, '');

            $components[] = [
                'type'    => 'BODY',
                'text'    => $data['body']['text'],
                'example' => ['body_text' => [ $exampleValues ]],
            ];
        }

        // =======
        // FOOTER
        // =======
        if (!empty($data['footer']['text'])) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $data['footer']['text'],
            ];
        }

        // =========
        // BUTTONS
        // =========
        if (!empty($data['buttons'])) {
            $buttons = array_map(fn ($b) => [
                'type' => strtoupper($b['type'] ?? 'URL'),
                'text' => $b['text'] ?? 'Visit website',
                'url'  => $b['url']  ?? 'https://example.com',
            ], $data['buttons']);

            $components[] = [
                'type'    => 'BUTTONS',
                'buttons' => $buttons,
            ];
        }

        // Simpan ke kolom components
        $data['components'] = $components;

        // bersihkan field builder (tidak ada di tabel)
        unset($data['header'], $data['body'], $data['footer'], $data['buttons']);

        return $data;
    }
}
