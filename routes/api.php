<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsappWebhookController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/webhook/whatsapp', [WhatsappWebhookController::class, 'handle']);
Route::get('/webhook/whatsapp', [WhatsappWebhookController::class, 'verify']);