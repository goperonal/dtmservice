<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsappWebhookController;

Route::get('/', function () {
    return view('welcome');
});

Route::match(['get', 'post'], '/webhook/whatsapp', [WhatsappWebhookController::class, 'handle']);