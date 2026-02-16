<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\WhatsAppWebhookController;

// Webhooks públicos
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'webhook']);

// Utilidad para servidor compartido (cPanel)
Route::get('/run-storage-link', function () {
    try {
        Artisan::call('storage:link');
        return 'Symlink creado correctamente: ' . Artisan::output();
    } catch (\Exception $e) {
        return 'Error al crear symlink: ' . $e->getMessage();
    }
});

// Auth routes
Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::post('/login', function (Illuminate\Http\Request $request) {
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required'
    ]);

    if (auth()->attempt($credentials, $request->boolean('remember'))) {
        $request->session()->regenerate();
        return redirect()->intended('/chat');
    }

    return back()->withErrors([
        'email' => 'Las credenciales no coinciden.',
    ])->onlyInput('email');
});

Route::post('/logout', function (Illuminate\Http\Request $request) {
    auth()->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('/login');
})->name('logout');

// Rutas protegidas
Route::middleware('auth')->group(function () {
    Route::redirect('/', '/chat');
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::resource('instances', InstanceController::class)->only(['index', 'store', 'destroy']);

    // Rutas Master
    Route::prefix('master')->name('master.')->group(function () {
        Route::get('/', [App\Http\Controllers\MasterController::class, 'index'])->name('index');
        Route::post('/companies', [App\Http\Controllers\MasterController::class, 'store'])->name('companies.store');
        Route::put('/companies/{company}', [App\Http\Controllers\MasterController::class, 'update'])->name('companies.update');
        Route::post('/impersonate/{company}', [App\Http\Controllers\MasterController::class, 'impersonate'])->name('impersonate');
    });
    
    Route::post('/stop-impersonating', [App\Http\Controllers\MasterController::class, 'stopImpersonating'])->name('stop-impersonating');

    // API routes for Chat (moved from api.php to share session)
    Route::prefix('api/chat')->group(function () {
        Route::get('/conversations', [ChatController::class, 'conversations']);
        Route::get('/conversations/{conversationId}/messages', [ChatController::class, 'messages']);
        Route::get('/updates', [ChatController::class, 'updates']);
        Route::post('/conversations/{conversationId}/send', [ChatController::class, 'sendMessage']);
        Route::post('/conversations/{conversationId}/send-image', [ChatController::class, 'sendImage']);
        Route::post('/conversations/{conversationId}/close', [ChatController::class, 'close']);
    });
});
