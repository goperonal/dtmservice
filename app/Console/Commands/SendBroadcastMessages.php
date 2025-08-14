<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BroadcastMessage;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SendBroadcastMessages extends Command
{
    protected $signature = 'broadcast:send';
    protected $description = 'Kirim pesan broadcast WhatsApp berdasarkan data di DB';

    public function handle(WhatsAppService $whatsappService)
    {
        $messages = BroadcastMessage::with(['campaign.whatsappTemplate', 'recipient'])
            ->where('status', 'pending')
            ->limit(20)
            ->get();

        if ($messages->isEmpty()) {
            $this->info("Tidak ada pesan pending.");
            return;
        }

        foreach ($messages as $message) {
            try {
                $template = $message->campaign->whatsappTemplate;
                $recipientNumber = $message->recipient->phone;
                

                if (!$template || !$recipientNumber) {
                    $this->warn("Data template atau nomor tidak ditemukan untuk ID {$message->id}");
                    $message->update([
                        'status' => 'failed',
                        'response_payload' => ['error' => 'Template atau nomor tidak ditemukan']
                    ]);
                    continue;
                }

                // Kirim
                $response = $whatsappService->sendWhatsAppTemplate($recipientNumber, $template);

                // Cek apakah API balas error
                if (isset($response['error'])) {
                    $message->update([
                        'status' => 'failed',
                        'response_payload' => $response
                    ]);
                    $this->error("Gagal kirim pesan {$message->id}: {$response['error']['message']}");
                    continue;
                }

                // Sukses
                $message->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'wamid' => $response['messages'][0]['id'] ?? null,
                    'response_payload' => $response
                ]);

                $this->info("Pesan {$message->id} terkirim ke {$recipientNumber}");
                sleep(5);

            } catch (\Throwable $e) {
                $message->update([
                    'status' => 'failed',
                    'response_payload' => ['error' => $e->getMessage()]
                ]);
                $this->error("Exception saat kirim pesan {$message->id}: {$e->getMessage()}");
            }
        }
    }
}
