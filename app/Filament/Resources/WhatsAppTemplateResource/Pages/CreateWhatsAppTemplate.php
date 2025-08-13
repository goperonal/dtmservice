<?php

namespace App\Filament\Resources\WhatsAppTemplateResource\Pages;

use App\Filament\Resources\WhatsAppTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use App\Services\WhatsAppTemplateService;

class CreateWhatsAppTemplate extends CreateRecord
{
    protected static string $resource = WhatsAppTemplateResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return WhatsAppTemplateResource::mutateFormDataBeforeCreate($data);
    }

    protected function afterCreate(): void
    {
        try {
            app(WhatsAppTemplateService::class)->pushTemplate($this->record);
            Notification::make()
                ->title('Template berhasil dikirim ke WhatsApp')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $this->record->update([
                'status' => 'FAILED',
            ]);
            
            Notification::make()
                ->title('Gagal kirim template ke WhatsApp')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

}
