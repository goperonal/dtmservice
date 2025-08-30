<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

use App\Models\BroadcastMessage;
use App\Models\Recipient;
use App\Models\Group;
use App\Models\WhatsAppTemplate;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    /**
     * Snapshot template (name, language, components) + bindings SEBELUM create.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tplName = (string) ($data['whatsapp_template_name'] ?? '');
        $tpl     = $tplName !== '' ? WhatsAppTemplate::where('name', $tplName)->first() : null;

        // Simpan nama template agar aman walau template dihapus
        $data['template_name']          = $tpl?->name ?? ($tplName ?: null);
        $data['whatsapp_template_name'] = $tpl?->name ?? ($tplName ?: null);

        // Tentukan bahasa (default 'id', atau pilih 'id' dari daftar languages kalau tersedia)
        $lang = 'id';
        if (is_array($tpl?->languages) && ! empty($tpl->languages)) {
            $lang = in_array('id', $tpl->languages, true) ? 'id' : (string) ($tpl->languages[0] ?? 'id');
        }
        $data['template_language'] = $lang;

        // Snapshot komponen (buat preview/audit)
        $data['template_components'] = \App\Filament\Resources\CampaignResource::getTemplateComponentsArray($tpl);

        // Pastikan bindings terdehydrate sebagai array
        $data['variable_bindings'] = is_array($data['variable_bindings'] ?? null) ? $data['variable_bindings'] : [];

        return $data;
    }

    /**
     * Setelah campaign tersimpan → generate broadcasts & enqueue jobs.
     */
    protected function afterCreate(): void
    {
        $data     = $this->form->getState();
        $campaign = $this->record;

        $mode         = $data['send_mode'] ?? 'single';
        $recipientIds = (array) ($data['recipient_id'] ?? []);
        $groupIds     = (array) ($data['group_id'] ?? []);

        // Ambil recipients sesuai mode
        $recipients = collect();
        if ($mode === 'single' && ! empty($recipientIds)) {
            $recipients = Recipient::whereIn('id', $recipientIds)->get();
        } elseif ($mode === 'group' && ! empty($groupIds)) {
            $recipients = Group::whereIn('id', $groupIds)
                ->with('recipients:id,name,phone')
                ->get()
                ->pluck('recipients')
                ->flatten()
                ->unique('id')
                ->values();
        }

        if ($recipients->isEmpty()) {
            Notification::make()
                ->title('Tidak ada penerima.')
                ->danger()
                ->send();
            return;
        }

        // Ambil body text dari snapshot components untuk fallback text
        $components = (array) ($campaign->template_components ?? []);
        $rawBody    = \App\Filament\Resources\CampaignResource::extractBodyTextFromComponents($components);
        $bindings   = is_array($campaign->variable_bindings ?? null) ? $campaign->variable_bindings : [];

        // Siapkan rows broadcasts (isi body hasil render -> fallback jika kirim teks)
        $now  = now();
        $rows = $recipients->map(function ($r) use ($campaign, $rawBody, $bindings, $now) {
            $rendered = \App\Filament\Resources\CampaignResource::renderBodyWithRecipientBindings(
                (string) $rawBody,
                $bindings,
                $r->loadMissing('groups') // penting untuk field 'group'
            );

            return [
                'campaign_id'             => $campaign->id,
                // biarkan id kalau ada, tapi nggak wajib
                'whatsapp_template_id'    => $campaign->whatsapp_template_id,
                // simpan juga NAME untuk tracking stabil
                'whatsapp_template_name'  => $campaign->whatsapp_template_name,
                'recipient_id'            => $r->id,
                'to'                      => $r->phone,
                'body'                    => $rendered,   // fallback text jika perlu
                'status'                  => 'pending',
                'created_at'              => $now,
                'updated_at'              => $now,
            ];
        })->all();

        DB::table('broadcasts')->insert($rows);

        // Enqueue jobs (batch) ke queue 'broadcasts'
        $ids = BroadcastMessage::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            $jobs  = $ids->map(fn ($id) => new \App\Jobs\SendBroadcastMessage($id))->all();
            $batch = Bus::batch($jobs)
                ->name('campaign-'.$campaign->id)
                ->onQueue('broadcasts')
                ->dispatch();

            Notification::make()
                ->title('Campaign dibuat')
                ->body($ids->count().' broadcast diantrikan (Batch: '.$batch->id.').')
                ->success()
                ->send();
        }
    }

    /**
     * Override form actions → modal konfirmasi biaya & jumlah penerima.
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('confirmCampaign')
                ->label('Send Campaign')
                ->color('primary')
                ->modalHeading('Konfirmasi Campaign')
                ->modalSubmitActionLabel('Simpan & Kirim')
                ->modalCancelActionLabel('Batal')
                ->modalContent(function () {
                    $data = $this->form->getState();

                    // Ambil kategori template by NAME (default UNKNOWN)
                    $tpl = WhatsAppTemplate::where('name', $data['whatsapp_template_name'] ?? null)->first();
                    $category = strtoupper((string) ($tpl->category ?? 'UNKNOWN'));

                    // Hitung jumlah recipients sesuai mode
                    $recipients = 0;
                    $mode = $data['send_mode'] ?? 'single';
                    if ($mode === 'single') {
                        $recipients = count(array_unique((array) ($data['recipient_id'] ?? [])));
                    } elseif ($mode === 'group') {
                        $recipients = Recipient::whereHas('groups', function ($q) use ($data) {
                            $q->whereIn('groups.id', (array) ($data['group_id'] ?? []));
                        })->distinct()->count('recipients.id');
                    }

                    // Harga per recipient (config + default)
                    $priceMap = [
                        'MARKETING'      => config('services.whatsapp_prices.marketing', 586.33),
                        'UTILITY'        => config('services.whatsapp_prices.utility', 356.65),
                        'AUTHENTICATION' => config('services.whatsapp_prices.authentication', 356.65),
                    ];
                    $price = $priceMap[$category] ?? $priceMap['AUTHENTICATION'];
                    $total = $recipients * $price;

                    // view konfirmasi kamu
                    return view('confirm-campaign', [
                        'campaignName' => $data['name'] ?? '(Tanpa Nama)',
                        'category'     => $category,
                        'recipients'   => $recipients,
                        'price'        => $price,
                        'total'        => $total,
                    ]);
                })
                ->action(fn () => $this->create()),
        ];
    }
}
