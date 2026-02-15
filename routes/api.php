<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;

Route::middleware('auth:web')->prefix('chat')->group(function () {
    Route::get('/conversations', [ChatController::class, 'conversations']);
    Route::get('/conversations/{conversationId}/messages', [ChatController::class, 'messages']);
    Route::get('/updates', [ChatController::class, 'updates']);
    
    Route::post('/conversations/{conversationId}/send', [ChatController::class, 'sendMessage']);
    Route::post('/conversations/{conversationId}/send-image', [ChatController::class, 'sendImage']);
    Route::post('/conversations/{conversationId}/close', [ChatController::class, 'close']);
});
