<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MediaProxyController;

Route::get('/', function () {
    // return view('welcome');
     return redirect()->route('filament.admin.pages.dashboard');
});

Route::get('/media/{mediaId}', [MediaProxyController::class, 'show'])->name('wa.media');