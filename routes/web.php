<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\WhatsAppWebhookController;
use Inertia\Inertia;

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
            $correctPath = '/home/intesoga/whatsapp.integracolombia.online/whatsapp/media/test_debug.txt';
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

// Debug para diagnosticar columnas de base de datos directamente desde Laravel
Route::get('/debug-db-columns', function () {
    try {
        $columns = Illuminate\Support\Facades\Schema::getColumnListing('whatsapp_messages');
        $dbName = Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        
        return response()->json([
            'database_name' => $dbName,
            'table_exists' => Illuminate\Support\Facades\Schema::hasTable('whatsapp_messages'),
            'columns_laravel_sees' => $columns,
            'has_incoming_invoice_id' => in_array('incoming_invoice_id', $columns)
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()]);
    }
});

// CSRF token refresh endpoint (used by axios interceptor to recover from 419)
Route::get('/csrf-token', function () {
    return response()->json(['token' => csrf_token()]);
})->name('csrf-token');

// Auth routes
Route::get('/login', function () {
    return Inertia::render('Auth/Login');
})->name('login')->middleware('guest');

Route::post('/login', function (Illuminate\Http\Request $request) {
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required'
    ]);

    if (auth()->attempt($credentials, $request->boolean('remember'))) {
        $request->session()->regenerate();
        
        $user = auth()->user();
        session(['company_id' => $user->company_id]);

        if ($user->hasRole('master')) {
            return redirect()->route('master.index');
        }

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
    Route::resource('instances', InstanceController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('/reports', [App\Http\Controllers\ReportsController::class, 'index'])
        ->middleware('permission:reports.view')->name('reports.index');

    Route::prefix('campaigns')->name('campaigns.')->group(function () {
        Route::get('/contacts/search', [App\Http\Controllers\WhatsAppCampaignController::class, 'searchContacts'])
            ->middleware('permission:campaigns.view')->name('contacts.search');
        Route::get('/', [App\Http\Controllers\WhatsAppCampaignController::class, 'index'])
            ->middleware('permission:campaigns.view')->name('index');
        Route::get('/{campaign}', [App\Http\Controllers\WhatsAppCampaignController::class, 'show'])
            ->middleware('permission:campaigns.view')->name('show');
        Route::post('/', [App\Http\Controllers\WhatsAppCampaignController::class, 'store'])
            ->middleware('permission:campaigns.create')->name('store');
        Route::post('/{campaign}/send', [App\Http\Controllers\WhatsAppCampaignController::class, 'send'])
            ->middleware('permission:campaigns.update')->name('send');
        Route::delete('/{campaign}', [App\Http\Controllers\WhatsAppCampaignController::class, 'destroy'])
            ->middleware('permission:campaigns.delete')->name('destroy');
    });

    Route::prefix('templates')->name('templates.')->group(function () {
        Route::get('/', [App\Http\Controllers\TemplateController::class, 'index'])
            ->middleware('permission:templates.view')->name('index');
        Route::get('/analytics', [App\Http\Controllers\TemplateController::class, 'analyticsIndex'])
            ->middleware('permission:templates.view')->name('analytics');
        Route::get('/create', [App\Http\Controllers\TemplateController::class, 'create'])
            ->middleware('permission:templates.create')->name('create');
        Route::get('/defaults', [App\Http\Controllers\TemplateController::class, 'defaultsIndex'])
            ->middleware('permission:templates.view')->name('defaults');
    });

    Route::prefix('api/templates')->group(function () {
        Route::get('/', [App\Http\Controllers\TemplateController::class, 'list'])
            ->middleware('permission:templates.view');
        Route::get('/analytics', [App\Http\Controllers\TemplateController::class, 'analytics'])
            ->middleware('permission:templates.view');
        Route::get('/analytics/conversations', [App\Http\Controllers\TemplateController::class, 'conversationAnalytics'])
            ->middleware('permission:templates.view');
        Route::post('/analytics/enable', [App\Http\Controllers\TemplateController::class, 'enableInsights'])
            ->middleware('permission:templates.view');
        Route::get('/defaults', [App\Http\Controllers\TemplateController::class, 'defaults'])
            ->middleware('permission:templates.view');
        Route::post('/defaults/{key}/sync', [App\Http\Controllers\TemplateController::class, 'syncDefault'])
            ->where('key', '[a-z0-9_]+')
            ->middleware('permission:templates.create');
        Route::get('/family/{name}', [App\Http\Controllers\TemplateController::class, 'family'])
            ->where('name', '[A-Za-z0-9_\-\.]+')
            ->middleware('permission:templates.view');
        Route::get('/{templateId}', [App\Http\Controllers\TemplateController::class, 'show'])
            ->where('templateId', '[0-9]+')
            ->middleware('permission:templates.view');
        Route::post('/upload-media', [App\Http\Controllers\TemplateController::class, 'uploadMedia'])
            ->middleware('permission:templates.create');
        Route::post('/', [App\Http\Controllers\TemplateController::class, 'store'])
            ->middleware('permission:templates.create');
    });

    Route::prefix('auto-responses')->name('auto-responses.')->group(function () {
        Route::get('/', [App\Http\Controllers\AutoResponseController::class, 'index'])
            ->middleware('permission:auto_responses.view')->name('index');
        Route::post('/', [App\Http\Controllers\AutoResponseController::class, 'store'])
            ->middleware('permission:auto_responses.create')->name('store');
        Route::put('/{auto_response}', [App\Http\Controllers\AutoResponseController::class, 'update'])
            ->middleware('permission:auto_responses.update')->name('update');
        Route::delete('/{auto_response}', [App\Http\Controllers\AutoResponseController::class, 'destroy'])
            ->middleware('permission:auto_responses.delete')->name('destroy');
    });
    Route::resource('users', UserController::class)->middleware('permission:users.view');
    Route::resource('roles', RoleController::class)->middleware('permission:roles.view');
    Route::get('/kanban', [ChatController::class, 'kanban'])->name('chat.kanban');

    // Quick Replies (Respuestas Rápidas) — admin page
    Route::get('/quick-replies', [App\Http\Controllers\QuickReplyController::class, 'index'])
        ->middleware('permission:quick_replies.view')
        ->name('quick-replies.index');

    // Macros — admin page
    Route::get('/macros', [App\Http\Controllers\MacroController::class, 'index'])
        ->middleware('permission:macros.view')
        ->name('macros.index');

    // Contactos — admin page
    Route::get('/contactos', [App\Http\Controllers\ContactController::class, 'index'])
        ->middleware('permission:contacts.view')
        ->name('contacts.index');

    // Rutas Master
    Route::prefix('master')->name('master.')->group(function () {
        Route::get('/', [App\Http\Controllers\MasterController::class, 'index'])->name('index');
        Route::post('/companies', [App\Http\Controllers\MasterController::class, 'store'])->name('companies.store');
        Route::put('/companies/{company}', [App\Http\Controllers\MasterController::class, 'update'])->name('companies.update');
        Route::post('/impersonate/{company}', [App\Http\Controllers\MasterController::class, 'impersonate'])->name('impersonate');

        Route::prefix('logs')->name('logs.')->group(function () {
            Route::get('/', [App\Http\Controllers\Master\LogsController::class, 'index'])->name('index');
            Route::get('/{file}', [App\Http\Controllers\Master\LogsController::class, 'show'])
                ->where('file', '[A-Za-z0-9_.\-]+')->name('show');
            Route::delete('/{file}', [App\Http\Controllers\Master\LogsController::class, 'destroy'])
                ->where('file', '[A-Za-z0-9_.\-]+')->name('destroy');
            Route::post('/{file}/clear', [App\Http\Controllers\Master\LogsController::class, 'clear'])
                ->where('file', '[A-Za-z0-9_.\-]+')->name('clear');
        });
    });
    
    Route::post('/stop-impersonating', [App\Http\Controllers\MasterController::class, 'stopImpersonating'])->name('stop-impersonating');

    // Settings routes
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [App\Http\Controllers\SettingsController::class, 'index'])->name('index');
        Route::put('/profile', [App\Http\Controllers\SettingsController::class, 'updateProfile'])->name('profile');
        Route::put('/password', [App\Http\Controllers\SettingsController::class, 'updatePassword'])->name('password');
        Route::delete('/sessions', [App\Http\Controllers\SettingsController::class, 'destroyOtherSessions'])->name('sessions.destroy');
    });

    Route::prefix('api/business-hours')->group(function () {
        Route::get('/', [App\Http\Controllers\BusinessHourController::class, 'index'])
            ->middleware('permission:business_hours.view');
        Route::post('/', [App\Http\Controllers\BusinessHourController::class, 'store'])
            ->middleware('permission:business_hours.create');
        Route::put('/{id}', [App\Http\Controllers\BusinessHourController::class, 'update'])
            ->middleware('permission:business_hours.update');
        Route::delete('/{id}', [App\Http\Controllers\BusinessHourController::class, 'destroy'])
            ->middleware('permission:business_hours.delete');
    });

    // WhatsApp settings API (readiness checklist + activations + profile)
    Route::prefix('api/settings/whatsapp')->group(function () {
        Route::get('/instances', [App\Http\Controllers\WhatsAppSettingsController::class, 'instances']);
        Route::get('/readiness', [App\Http\Controllers\WhatsAppSettingsController::class, 'readiness']);
        Route::get('/phone-numbers', [App\Http\Controllers\WhatsAppSettingsController::class, 'phoneNumbers']);
        Route::get('/profile', [App\Http\Controllers\WhatsAppSettingsController::class, 'getProfile']);
        Route::post('/subscribe-webhook', [App\Http\Controllers\WhatsAppSettingsController::class, 'subscribeWebhook'])
            ->middleware('permission:instances.update');
        Route::post('/register-number', [App\Http\Controllers\WhatsAppSettingsController::class, 'registerNumber'])
            ->middleware('permission:instances.update');
        Route::post('/request-code', [App\Http\Controllers\WhatsAppSettingsController::class, 'requestCode'])
            ->middleware('permission:instances.update');
        Route::post('/verify-code', [App\Http\Controllers\WhatsAppSettingsController::class, 'verifyCode'])
            ->middleware('permission:instances.update');
        Route::post('/enable-insights', [App\Http\Controllers\WhatsAppSettingsController::class, 'enableInsights'])
            ->middleware('permission:instances.update');
        Route::post('/profile', [App\Http\Controllers\WhatsAppSettingsController::class, 'updateProfile'])
            ->middleware('permission:instances.update');
        Route::post('/profile/photo', [App\Http\Controllers\WhatsAppSettingsController::class, 'updateProfilePhoto'])
            ->middleware('permission:instances.update');

        // Configuración de llamadas: estado, habilitar función y toggle de salientes
        Route::get('/calling', [App\Http\Controllers\WhatsAppSettingsController::class, 'callingSettings']);
        Route::post('/calling/enable', [App\Http\Controllers\WhatsAppSettingsController::class, 'enableCalling'])
            ->middleware('permission:instances.update');
        Route::post('/calling', [App\Http\Controllers\WhatsAppSettingsController::class, 'updateCallingSettings'])
            ->middleware('permission:instances.update');

        // Plantilla de reinicio de conversación (reabrir chats fuera de la ventana de 24h)
        Route::get('/resume-template', [App\Http\Controllers\WhatsAppSettingsController::class, 'resumeTemplateSettings']);
        Route::post('/resume-template', [App\Http\Controllers\WhatsAppSettingsController::class, 'updateResumeTemplate'])
            ->middleware('permission:instances.update');
    });

    // API routes for Chat (moved from api.php to share session)
    Route::prefix('api/chat')->group(function () {
        Route::get('/conversations', [ChatController::class, 'conversations']);
        Route::get('/folders', [ChatController::class, 'folders']);
        Route::post('/conversations/start', [ChatController::class, 'startConversation']);
        Route::post('/conversations/close-bulk', [ChatController::class, 'closeBulk'])->middleware('permission:chat.update');
        Route::get('/templates', [ChatController::class, 'templates']);
        Route::post('/templates/ensure-resume', [ChatController::class, 'ensureResumeTemplate']);
        Route::get('/conversations/{conversationId}/messages', [ChatController::class, 'messages']);
        Route::get('/updates', [ChatController::class, 'updates']);
        Route::post('/conversations/{conversationId}/send', [ChatController::class, 'sendMessage']);
        Route::post('/conversations/{conversationId}/send-template', [ChatController::class, 'sendTemplate']);
        Route::post('/conversations/{conversationId}/template-media', [ChatController::class, 'uploadTemplateMedia']);
        Route::post('/conversations/{conversationId}/note', [ChatController::class, 'storeNote']);
        Route::post('/conversations/{conversationId}/send-image', [ChatController::class, 'sendImage']);
        Route::post('/conversations/{conversationId}/send-audio', [ChatController::class, 'sendAudio']);
        Route::post('/conversations/{conversationId}/close', [ChatController::class, 'close']);
        Route::post('/conversations/{conversationId}/reopen', [ChatController::class, 'reopen']);
        Route::delete('/conversations/{conversationId}', [ChatController::class, 'destroy']);
        Route::post('/conversations/{conversationId}/assign', [ChatController::class, 'assign'])->middleware('permission:chat.update');
        Route::post('/conversations/{conversationId}/assign-me', [ChatController::class, 'assignToMe']);
        Route::post('/conversations/{conversationId}/attach-contact', [App\Http\Controllers\ContactController::class, 'attachConversation'])->middleware('permission:contacts.view');
        Route::get('/users', [UserController::class, 'getCompanyUsers']);

        // Llamadas WhatsApp (Fase 2: permiso para salientes)
        Route::post('/conversations/{conversationId}/call-permission', [App\Http\Controllers\CallController::class, 'requestPermission']);
        Route::get('/conversations/{conversationId}/call-permission', [App\Http\Controllers\CallController::class, 'permissionStatus']);

        // Historial de llamadas
        Route::get('/calls', [App\Http\Controllers\CallController::class, 'history']);
        Route::get('/conversations/{conversationId}/calls', [App\Http\Controllers\CallController::class, 'conversationHistory']);
    });

    // Contacts API routes
    Route::prefix('api/contacts')->group(function () {
        Route::get('/list', [App\Http\Controllers\ContactController::class, 'list'])->middleware('permission:contacts.view');
        Route::post('/', [App\Http\Controllers\ContactController::class, 'store'])->middleware('permission:contacts.create');
        Route::put('/{contact}', [App\Http\Controllers\ContactController::class, 'update'])->middleware('permission:contacts.update');
        Route::delete('/{contact}', [App\Http\Controllers\ContactController::class, 'destroy'])->middleware('permission:contacts.delete');
    });

    // Kanban API routes
    Route::prefix('api/kanban')->group(function () {
        Route::get('/columns', [App\Http\Controllers\KanbanController::class, 'columns']);
        Route::get('/counts', [App\Http\Controllers\KanbanController::class, 'columnCounts']);
        Route::post('/columns', [App\Http\Controllers\KanbanController::class, 'storeColumn']);
        Route::put('/columns/{id}', [App\Http\Controllers\KanbanController::class, 'updateColumn']);
        Route::delete('/columns/{id}', [App\Http\Controllers\KanbanController::class, 'deleteColumn']);
        Route::get('/columns/{id}/cards', [App\Http\Controllers\KanbanController::class, 'columnCards']);
        Route::post('/conversations/{id}/move', [App\Http\Controllers\KanbanController::class, 'moveCard']);
        Route::post('/cards', [App\Http\Controllers\KanbanController::class, 'storeCard']);
    });

    // Quick Replies API routes
    Route::prefix('api/quick-replies')->group(function () {
        Route::get('/', [App\Http\Controllers\QuickReplyController::class, 'list']);
        Route::post('/', [App\Http\Controllers\QuickReplyController::class, 'store'])->middleware('permission:quick_replies.create');
        Route::put('/{quickReply}', [App\Http\Controllers\QuickReplyController::class, 'update'])->middleware('permission:quick_replies.update');
        Route::delete('/{quickReply}', [App\Http\Controllers\QuickReplyController::class, 'destroy'])->middleware('permission:quick_replies.delete');
    });

    // Macros API routes
    Route::prefix('api/macros')->group(function () {
        Route::get('/', [App\Http\Controllers\MacroController::class, 'list']);
        Route::post('/', [App\Http\Controllers\MacroController::class, 'store'])->middleware('permission:macros.create');
        Route::put('/{macro}', [App\Http\Controllers\MacroController::class, 'update'])->middleware('permission:macros.update');
        Route::delete('/{macro}', [App\Http\Controllers\MacroController::class, 'destroy'])->middleware('permission:macros.delete');
        Route::post('/{macro}/run/{conversationId}', [App\Http\Controllers\MacroController::class, 'run'])->middleware('permission:macros.run');
    });

    // Tag API routes
    Route::prefix('api/tags')->group(function () {
        Route::get('/', [App\Http\Controllers\TagController::class, 'index']);
        Route::post('/', [App\Http\Controllers\TagController::class, 'store']);
        Route::put('/{tag}', [App\Http\Controllers\TagController::class, 'update']);
        Route::delete('/{tag}', [App\Http\Controllers\TagController::class, 'destroy']);
        Route::post('/conversations/{id}/attach', [App\Http\Controllers\TagController::class, 'attachToConversation']);
        Route::post('/conversations/{id}/detach', [App\Http\Controllers\TagController::class, 'detachFromConversation']);
    });

    // In-app notifications (mentions + system announcements bell)
    Route::prefix('api/notifications')->group(function () {
        Route::get('/', [App\Http\Controllers\NotificationController::class, 'index']);
        Route::post('/{id}/read', [App\Http\Controllers\NotificationController::class, 'markRead']);
        Route::post('/read-all', [App\Http\Controllers\NotificationController::class, 'markAllRead']);
        Route::delete('/{id}', [App\Http\Controllers\NotificationController::class, 'destroy']);
        Route::delete('/', [App\Http\Controllers\NotificationController::class, 'destroyAll']);
    });

    // System announcements (admin-emitted notifications)
    Route::get('/announcements', [App\Http\Controllers\SystemNotificationController::class, 'index'])
        ->middleware('permission:notifications.send')->name('announcements.index');
    Route::prefix('api/announcements')->middleware('permission:notifications.send')->group(function () {
        Route::get('/', [App\Http\Controllers\SystemNotificationController::class, 'history']);
        Route::post('/', [App\Http\Controllers\SystemNotificationController::class, 'store']);
    });

    // Integraciones — Webhooks salientes (parametrizables por empresa)
    Route::get('/integrations', [App\Http\Controllers\WebhookEndpointController::class, 'index'])
        ->middleware('permission:integrations.view')->name('integrations.index');
    Route::prefix('api/webhooks')->group(function () {
        Route::get('/', [App\Http\Controllers\WebhookEndpointController::class, 'list'])
            ->middleware('permission:integrations.view');
        Route::post('/', [App\Http\Controllers\WebhookEndpointController::class, 'store'])
            ->middleware('permission:integrations.create');
        Route::put('/{webhook}', [App\Http\Controllers\WebhookEndpointController::class, 'update'])
            ->middleware('permission:integrations.update');
        Route::delete('/{webhook}', [App\Http\Controllers\WebhookEndpointController::class, 'destroy'])
            ->middleware('permission:integrations.delete');
        Route::post('/{webhook}/test', [App\Http\Controllers\WebhookEndpointController::class, 'test'])
            ->middleware('permission:integrations.update');
        Route::get('/{webhook}/deliveries', [App\Http\Controllers\WebhookEndpointController::class, 'deliveries'])
            ->middleware('permission:integrations.view');
    });

    // Integraciones — Pagos a facturas (software Integra, API V1 con token maestro)
    Route::prefix('api/integrations')->group(function () {
        Route::get('/', [App\Http\Controllers\IntegrationController::class, 'index'])
            ->middleware('permission:integrations.view');
        Route::post('/{key}/connect', [App\Http\Controllers\IntegrationController::class, 'connect'])
            ->middleware('permission:integrations.update');
        Route::get('/{key}/status', [App\Http\Controllers\IntegrationController::class, 'status'])
            ->middleware('permission:integrations.view');
        Route::post('/{key}/activate', [App\Http\Controllers\IntegrationController::class, 'activate'])
            ->middleware('permission:integrations.update');
        Route::post('/{key}/disconnect', [App\Http\Controllers\IntegrationController::class, 'disconnect'])
            ->middleware('permission:integrations.update');
        Route::post('/{key}/sync', [App\Http\Controllers\IntegrationController::class, 'syncContacts'])
            ->middleware('permission:integrations.update');
        Route::get('/{key}/sync-status', [App\Http\Controllers\IntegrationController::class, 'syncStatus'])
            ->middleware('permission:integrations.view');

        // Acciones usadas desde el chat por los agentes (solo requieren sesión).
        Route::get('/invoice-payments/clients', [App\Http\Controllers\IntegrationController::class, 'searchClients']);
        Route::get('/invoice-payments/invoices', [App\Http\Controllers\IntegrationController::class, 'invoices']);
        Route::get('/invoice-payments/catalogs', [App\Http\Controllers\IntegrationController::class, 'catalogs']);
        Route::post('/invoice-payments/pay', [App\Http\Controllers\IntegrationController::class, 'pay']);
    });
});
