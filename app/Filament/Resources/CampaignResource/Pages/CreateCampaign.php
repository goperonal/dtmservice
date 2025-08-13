<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Models\BroadcastMessage;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    protected function afterCreate(): void
    {
        // Ambil semua recipients yang dipilih
        $recipients = $this->record->recipients;

        foreach ($recipients as $recipient) {
            BroadcastMessage::create([
                'campaign_id' => $this->record->id,
                'whatsapp_template_id' => $this->record->whatsapp_template_id,
                'recipient_id' => $recipient->id,
                'status' => 'pending',
            ]);
        }
    }
}
