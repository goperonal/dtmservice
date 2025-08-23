<?php

namespace App\Filament\Resources\WhatsAppTemplateResource\Pages;

use App\Filament\Resources\WhatsAppTemplateResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use App\Services\WhatsAppTemplateService;
use Illuminate\Support\Facades\Storage;

class CreateWhatsAppTemplate extends CreateRecord
{
    protected static string $resource = WhatsAppTemplateResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // mapping components langsung di sini
        $components = [];

        // HEADER
        if (!empty($data['header']['type']) && $data['header']['type'] !== 'none') {
            if ($data['header']['type'] === 'image' && !empty($data['header']['media_url'])) {
                $path = Storage::disk('public')->path($data['header']['media_url']);
                $mediaId = app(WhatsAppTemplateService::class)->uploadMediaToWhatsApp($path);
                $components[] = ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => [$mediaId]]];
                $data['header_image_url'] = Storage::url($data['header']['media_url']);
            }

            if ($data['header']['type'] === 'text' && !empty($data['header']['text'])) {
                $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $data['header']['text']];
            }
        }

        // BODY
        if (!empty($data['body']['text'])) {
            preg_match_all('/\{\{(\d+)\}\}/', $data['body']['text'], $matches);
            $paramCount = count($matches[1]);
            $exampleValues = !empty($data['body']['example'])
                ? array_map('trim', explode(',', $data['body']['example']))
                : [];
            $exampleValues = array_pad($exampleValues, $paramCount, '');
            $components[] = ['type' => 'BODY', 'text' => $data['body']['text'], 'example' => ['body_text' => [$exampleValues]]];
        }

        // FOOTER
        if (!empty($data['footer']['text'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $data['footer']['text']];
        }

        // BUTTONS
        if (!empty($data['buttons'])) {
            $buttons = array_map(fn($b) => ['type' => strtoupper($b['type']), 'text' => $b['text'], 'url' => $b['url']], $data['buttons']);
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        $data['components'] = $components;

        // hapus field sementara
        unset($data['header'], $data['body'], $data['footer'], $data['buttons']);

        return $data;
    }

    // protected function afterCreate(): void
    // {
    //     try {
    //         app(WhatsAppTemplateService::class)->pushTemplate($this->record);
    //         Notification::make()
    //             ->title('Template berhasil dikirim ke WhatsApp')
    //             ->success()
    //             ->send();
    //     } catch (\Throwable $e) {
    //         $this->record->update(['status' => 'FAILED']);
    //         Notification::make()
    //             ->title('Gagal kirim template ke WhatsApp')
    //             ->body($e->getMessage())
    //             ->danger()
    //             ->send();
    //     }
    // }
}
