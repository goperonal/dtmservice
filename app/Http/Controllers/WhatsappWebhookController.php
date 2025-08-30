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

                // ===== STATUS UPDATE =====
                if (!empty($value['statuses'])) {
                    foreach ($value['statuses'] as $status) {
                        $wamid = $status['id'] ?? null;
                        $broadcast = BroadcastMessage::where('wamid', $wamid)->first();

                        WhatsappWebhook::create([
                            'broadcast_id' => $broadcast->id ?? null,
                            'event_type'   => 'status',
                            'message_id'   => $wamid,
                            'status'       => $status['status'] ?? null,
                            'to_number'    => $status['recipient_id'] ?? null,
                            'conversation_id'        => data_get($status, 'conversation.id'),
                            'conversation_category'  => data_get($status, 'pricing.category'),
                            'pricing_model'          => data_get($status, 'pricing.pricing_model'),
                            'timestamp'    => isset($status['timestamp']) ? \Carbon\Carbon::createFromTimestamp($status['timestamp']) : null,
                            'payload'      => json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ]);

                        if ($broadcast) {
                            $broadcast->update(['status' => ($status['status'] ?? $broadcast->status)]);
                        }
                    }
                }

                // ===== PESAN MASUK =====
                if (!empty($value['messages'])) {
                    foreach ($value['messages'] as $msg) {
                        $type = $msg['type'] ?? 'text';

                        $mediaId = data_get($msg, "{$type}.id");
                        $mime    = data_get($msg, "{$type}.mime_type");

                        $row = WhatsappWebhook::create([
                            'event_type'  => 'message',
                            'message_id'  => $msg['id'] ?? null,
                            'message_type'=> $type,
                            'status'      => null,
                            'from_number' => $msg['from'] ?? null,
                            'to_number'   => $value['metadata']['display_phone_number'] ?? null,
                            'timestamp'   => isset($msg['timestamp']) ? \Carbon\Carbon::createFromTimestamp($msg['timestamp']) : null,
                            'payload'     => json_encode($msg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                            'media_id'    => $mediaId,
                            'media_mime'  => $mime,
                        ]);

                        // Kalau ada media → queue fetch sekali
                        if ($mediaId) {
                            \App\Jobs\FetchWhatsappMedia::dispatch($row->id)->onQueue('default');
                        }
                    }
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }


}
