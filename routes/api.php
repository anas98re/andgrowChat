<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

// Routes called from the chat front-end (Vue)
Route::post('/chat', [ChatController::class, 'store'])
    ->middleware('throttle:30,1');

// The correct path to retrieve the conversation using the digital ID (if you need it in the future)
Route::get('/conversation/{conversation}', [ChatController::class, 'show']);

// Missing path that caused 404 error (most important)
Route::get('/conversation/by-session/{sessionId}', [ChatController::class, 'showBySessionId']);