<?php

use App\Http\Controllers\ChatController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/chat', [ChatController::class, 'store'])
    ->middleware('throttle:30,1'); // Rate limit: max 30 requests per minute per IP

Route::get('/conversation/{conversation}', [ChatController::class, 'show']);
