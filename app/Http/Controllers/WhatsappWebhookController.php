<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\BroadcastMessage;
use App\Models\WhatsappWebhook;

class WhatsappWebhookController extends Controller
{
    /**
     * Verify webhook (Facebook GET request)
     */

    public function verify(Request $request)
    {
        $verifyToken = config('services.whatsapp.verify_token'); // from config/services.php

        $mode = $request->get('hub_mode');
        $token = $request->get('hub_verify_token');
        $challenge = $request->get('hub_challenge');

        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $verifyToken) {
                return response($challenge, 200);
            } else {
                return response('Forbidden', 403);
            }
        }

        return response('Bad Request', 400);
    }

    /**
     * Handle incoming webhook (Facebook POST request)
     */
    public function handle(Request $request)
    {
        $data = $request->all();

        foreach ($data['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                // STATUS UPDATE
                if (!empty($value['statuses'])) {
                    foreach ($value['statuses'] as $status) {
                        $wamid = $status['id'] ?? null;

                        // Cari broadcast berdasarkan wamid
                        $broadcast = BroadcastMessage::where('wamid', $wamid)->first();

                        // Simpan webhook
                        WhatsappWebhook::create([
                            'broadcast_id' => $broadcast->id ?? null,
                            'event_type' => 'status',
                            'message_id' => $wamid,
                            'status' => $status['status'] ?? null,
                            'to_number' => $status['recipient_id'] ?? null,
                            'conversation_id' => $status['conversation']['id'] ?? null,
                            'conversation_category' => $status['pricing']['category'] ?? null,
                            'pricing_model' => $status['pricing']['pricing_model'] ?? null,
                            'timestamp' => isset($status['timestamp']) ? Carbon::createFromTimestamp($status['timestamp']) : null,
                            'payload' => json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        ]);

                        // Update status di broadcast jika ada
                        if ($broadcast) {
                            $broadcast->update([
                                'status' => $status['status']
                            ]);
                        }
                    }
                }

                // PESAN MASUK (jika ada)
                if (!empty($value['messages'])) {
                    foreach ($value['messages'] as $msg) {
                        WhatsappWebhook::create([
                            'event_type' => 'message',
                            'message_id' => $msg['id'] ?? null,
                            'status' => null,
                            'from_number' => $msg['from'] ?? null,
                            'to_number' => $value['metadata']['display_phone_number'] ?? null,
                            'timestamp' => isset($msg['timestamp']) ? Carbon::createFromTimestamp($msg['timestamp']) : null,
                            'payload' => json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        ]);
                    }
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
