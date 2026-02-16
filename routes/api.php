<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;

// Routes moved to web.php to share session state
// But we keep this api.php for external integrations as requested

use App\Http\Controllers\Api\MessageApiController;

Route::prefix('v1')->group(function () {
    Route::post('/messages/send', [MessageApiController::class, 'sendMessage']);
    Route::post('/messages/template', [MessageApiController::class, 'sendTemplate']);
    Route::post('/messages/register', [MessageApiController::class, 'registerMessage']);
});
