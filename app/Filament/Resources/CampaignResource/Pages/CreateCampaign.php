<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\BroadcastMessage;
use App\Models\Recipient;
use App\Models\Group;
use App\Models\WhatsAppTemplate;
use Filament\Actions\Action; // <= WAJIB diimport
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use App\Jobs\SendBroadcastMessage;


class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    protected function afterCreate(): void
    {
        $data = $this->form->getState();

        $mode         = $data['send_mode'] ?? null;
        $recipientIds = (array)($data['recipient_id'] ?? []);
        $groupIds     = (array)($data['group_id'] ?? []);

        $recipients = collect();

        if ($mode === 'single' && !empty($recipientIds)) {
            $recipients = \App\Models\Recipient::whereIn('id', $recipientIds)->get();
        }

        if ($mode === 'group' && !empty($groupIds)) {
            $recipients = \App\Models\Group::whereIn('id', $groupIds)
                ->with('recipients')
                ->get()
                ->pluck('recipients')
                ->flatten()
                ->unique('id');
        }

        if ($recipients->isEmpty()) {
            return;
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

        \App\Models\BroadcastMessage::insert($rows);

        // === ENQUEUE SETELAH COMMIT (aman dari race condition) ===
        DB::afterCommit(function () {
            $ids = \App\Models\BroadcastMessage::where('campaign_id', $this->record->id)
                ->where('status', 'pending')
                ->pluck('id');

            if ($ids->isEmpty()) return;

            $jobs = $ids->map(fn ($id) => new SendBroadcastMessage($id))->all();

            $batch = Bus::batch($jobs)
                ->name('campaign-'.$this->record->id)
                ->onQueue('broadcasts')
                ->dispatch(); // atau ->dispatchAfterResponse()

            Notification::make()
                ->title('Campaign dibuat')
                ->body($ids->count().' broadcast diantrikan (Batch: '.$batch->id.').')
                ->success()
                ->send();
        });
    }


    protected function getFormActions(): array
    {
        return [
            Action::make('confirmCampaign')
                ->label('Save Campaign')
                ->modalHeading('Konfirmasi Campaign') // judul popup
                ->modalSubmitActionLabel('Simpan & Kirim')
                ->modalCancelActionLabel('Batal')
                ->modalContent(function ($livewire, $record) {
                    $data = $this->form->getState();

                    $template = WhatsAppTemplate::find($data['whatsapp_template_id']);
                    $category = $template->category ?? 'Unknown';

                    $recipients = 0;
                    if ($data['send_mode'] === 'single') {
                        $recipients = count((array) $data['recipient_id']);
                    } elseif ($data['send_mode'] === 'group') {
                        $recipients = Recipient::whereHas('groups', function ($q) use ($data) {
                            $q->whereIn('groups.id', (array) $data['group_id']);
                        })->count();
                    }

                    $pricePerRecipient = match ($category) {
                        'MARKETING' => config('services.whatsapp_prices.marketing', 586.33),
                        'UTILITY'   => config('services.whatsapp_prices.utility', 356.65),
                        default     => config('services.whatsapp_prices.authentication', 356.65),
                    };
                    $total = $recipients * $pricePerRecipient;

                    // Konten popup
                    return view('confirm-campaign', [
                        'campaignName' => $data['name'] ?? '(Tanpa Nama)',
                        'category'   => $category,
                        'recipients' => $recipients,
                        'price'      => $pricePerRecipient,
                        'total'      => $total,
                    ]);
                })
                ->action(function () {
                    // jalankan simpan record setelah confirm
                    $this->create();
                })
                ->color('primary'),
        ];
    }
}
