<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BroadcastMessage;
use Illuminate\Support\Facades\Bus;
use App\Jobs\SendBroadcastMessage;

class SendBroadcastMessages extends Command
{
    protected $signature = 'broadcast:send {--campaign=} {--chunk=1000}';
    protected $description = 'Enqueue jobs untuk mengirim broadcast WhatsApp';

    public function handle()
    {
        $campaignId = $this->option('campaign');
        $chunk      = (int) $this->option('chunk');

        $query = BroadcastMessage::query()
            ->where('status', 'pending')
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId));

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Tidak ada pesan pending.');
            return self::SUCCESS;
        }

        $this->info("Menemukan {$total} pesan pending. Mengantrikan...");

        $query->orderBy('id')->chunkById($chunk, function ($messages) {
            $jobs = $messages->pluck('id')->map(fn($id) => new SendBroadcastMessage($id))->all();

            $batch = Bus::batch($jobs)
                ->name('manual-broadcast')
                ->onQueue('broadcasts')
                ->dispatch();

            $this->info("Batch {$batch->id} dibuat (".count($jobs)." jobs).");
        });

        return self::SUCCESS;
    }
}
