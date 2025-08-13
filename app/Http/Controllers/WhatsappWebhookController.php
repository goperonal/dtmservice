<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('WhatsApp Webhook Received', $request->all());

        // ✅ Handle Webhook Verification (GET)
        if ($request->isMethod('get')) {
            $verifyToken = config('services.whatsapp.verify_token');

            $mode = $request->query('hub_mode');
            $token = $request->query('hub_verify_token');
            $challenge = $request->query('hub_challenge');

            Log::info('WhatsApp Webhook Verification', ['verify_token' => $verifyToken]);
            Log::info('WhatsApp Webhook mode', ['mode' => $mode]);

            if ($mode === 'subscribe' && $token === $verifyToken) {
                
                return response($challenge, 200)
                    ->header('Content-Type', 'text/plain');
            }

            return response('Invalid verify token', 403);
        }

        // ✅ Handle Incoming Messages / Events (POST)
        if ($request->isMethod('post')) {
            $data = $request->all();

            // Contoh log
            Log::info('WhatsApp Event Data', $data);

            // Disini kamu bisa proses event masuk sesuai kebutuhan
            // Contoh: jika ada message
            if (isset($data['entry'][0]['changes'][0]['value']['messages'])) {
                foreach ($data['entry'][0]['changes'][0]['value']['messages'] as $message) {
                    Log::info('Incoming WhatsApp Message', $message);
                    // Lakukan proses, misalnya simpan ke DB
                }
            }

            return response()->json(['status' => 'success'], 200);
        }

        return response()->json(['error' => 'Unsupported method'], 405);
    }
}
