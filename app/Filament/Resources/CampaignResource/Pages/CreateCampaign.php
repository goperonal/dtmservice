<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Models\BroadcastMessage;
use App\Models\Recipient;
use Illuminate\Support\Facades\Log;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    protected function afterCreate(): void
    {
        // Ambil nilai dari form (bukan dari record)
        $data = $this->form->getState();
        Log::info($data);

        $mode   = $data['send_mode'] ?? null;
        $ids    = (array)($data['recipient_id'] ?? []);
        $group  = $data['group'] ?? null;

        $recipients = collect();

        if ($mode === 'single' && !empty($ids)) {
            $recipients = Recipient::whereIn('id', $ids)->get();
        } elseif ($mode === 'group' && !empty($group)) {
            $recipients = Recipient::where('group', $group)->get();
        }

        if ($recipients->isEmpty()) {
            return; // tidak ada yang dikirim
        }

        $now  = now();
        $rows = $recipients->map(fn ($r) => [
            'campaign_id'           => $this->record->id,
            'whatsapp_template_id'  => $this->record->whatsapp_template_id,
            'recipient_id'          => $r->id,
            'status'                => 'pending',
            'created_at'            => $now,
            'updated_at'            => $now,
        ])->all();

        // cepat & hemat query; pakai insert (atau upsert kalau butuh anti-duplikat)
        BroadcastMessage::insert($rows);
    }
}
