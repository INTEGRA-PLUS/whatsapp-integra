<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\WhatsAppWebhookController;

// Webhooks públicos
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'webhook']);

// Utilidad para servidor compartido (cPanel) - Estructura personalizada + Permisos
Route::get('/run-storage-link', function () {
    $targetFolder = storage_path('app/public');
    $linkFolder = $_SERVER['DOCUMENT_ROOT'] . '/storage';

    $output = [];

    // 1. Crear directorios si no existen con permisos amplios
    if (!file_exists($targetFolder)) {
        mkdir($targetFolder, 0755, true);
        $output[] = "Directorio creado: $targetFolder";
    }

    // 2. Revisar/Crear Symlink
    if (file_exists($linkFolder)) {
        if (is_link($linkFolder)) {
            $output[] = "El link ya existe.";
        } else {
            return "ERROR: Ya existe una carpeta 'storage' que NO es un link.";
        }
    } else {
        try {
            symlink($targetFolder, $linkFolder);
            $output[] = "✅ Link creado exitosamente.";
        } catch (\Exception $e) {
            return "❌ Error creando link: " . $e->getMessage();
        }
    }

    // 3. INTENTO DE CORREGIR PERMISOS (Fix 403 Forbidden)
    try {
        // Asegurar que la carpeta fisica tenga permisos de ejecución/lectura
        chmod($targetFolder, 0755); 
        $output[] = "Permisos carpeta root public: 0755 verificado.";

        // Recorrer subcarpetas (whatsapp, media, etc)
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetFolder));
        
        foreach ($iterator as $item) {
            if ($item->getBasename() == '..') continue; // Saltar padre
            
            if ($item->isDir()) {
                chmod($item->getPathname(), 0755);
            } else {
                chmod($item->getPathname(), 0644);
            }
        }
        $output[] = "✅ Permisos corregidos recursivamente (Dir: 755, Files: 644).";
        
    } catch (\Exception $e) {
        $output[] = "⚠️ No se pudieron cambiar todos los permisos: " . $e->getMessage();
    }

    return implode("<br>", $output);
});

// Debug para verificar rutas y permisos de escritura en cPanel
Route::get('/debug-path-test', function () {
    $info = [];
    $info['document_root'] = $_SERVER['DOCUMENT_ROOT'] ?? 'N/A';
    $info['public_path'] = public_path();
    $info['disk_config'] = config('filesystems.disks.public_uploads');
    
    // Intentar escribir un archivo de prueba
    try {
        // Clear config cache to ensure new filesystem config is loaded
        Artisan::call('optimize:clear');
        $info['cache_cleared'] = "Cache limpiada (optimize:clear)";

        $testFile = 'whatsapp/media/test_debug.txt';
        $content = "Prueba de escritura: " . now();
        
        $success = Storage::disk('public_uploads')->put($testFile, $content);
        
        if ($success) {
            // Check explicit path
            $correctPath = '/home/intesoga/whatsapp.integracolombia.com/whatsapp/media/test_debug.txt';
            $info['write_status'] = "✅ Éxito al escribir archivo";
            $info['file_check_correct_path'] = file_exists($correctPath) ? "✅ EXITOSO: Archivo encontrado en: $correctPath" : "❌ FALLÓ: Archivo NO encontrado en: $correctPath";
            
            // Check wrong path just in case
            $wrongPath = public_path($testFile);
            $info['file_check_wrong_path'] = file_exists($wrongPath) ? "⚠️ ADVERTENCIA: Archivo encontrado en la ruta interna (incorrecta): $wrongPath" : "✅ Correcto: Archivo NO está en la ruta interna";
            
            $info['url_generated'] = Storage::disk('public_uploads')->url($testFile);
        } else {
            $info['write_status'] = "❌ Falló la escritura (Storage::put retornó false)";
        }
        
    } catch (\Exception $e) {
        $info['write_error'] = $e->getMessage();
    }
    
    return $info;
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
