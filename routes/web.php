<?php

use App\Http\Controllers\AiFlowSettingsController;
use App\Http\Controllers\Auth\ContrasenaOlvidadaController;
use App\Http\Controllers\AutoResponseController;
use App\Http\Controllers\BusinessHourController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmbeddedSignupController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\MacroController;
use App\Http\Controllers\Master\LogsController;
use App\Http\Controllers\Master\MessagesController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\QuickReplyController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemNotificationController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookEndpointController;
use App\Http\Controllers\WhatsAppCampaignController;
use App\Http\Controllers\WhatsAppMenuController;
use App\Http\Controllers\WhatsAppSettingsController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

// Webhooks públicos
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'webhook']);

// Utilidad para servidor compartido (cPanel) - Estructura personalizada + Permisos
Route::get('/run-storage-link', function () {
    $targetFolder = storage_path('app/public');
    $linkFolder = $_SERVER['DOCUMENT_ROOT'].'/storage';

    $output = [];

    // 1. Crear directorios si no existen con permisos amplios
    if (! file_exists($targetFolder)) {
        mkdir($targetFolder, 0755, true);
        $output[] = "Directorio creado: $targetFolder";
    }

    // 2. Revisar/Crear Symlink
    if (file_exists($linkFolder)) {
        if (is_link($linkFolder)) {
            $output[] = 'El link ya existe.';
        } else {
            return "ERROR: Ya existe una carpeta 'storage' que NO es un link.";
        }
    } else {
        try {
            symlink($targetFolder, $linkFolder);
            $output[] = '✅ Link creado exitosamente.';
        } catch (Exception $e) {
            return '❌ Error creando link: '.$e->getMessage();
        }
    }

    // 3. INTENTO DE CORREGIR PERMISOS (Fix 403 Forbidden)
    try {
        // Asegurar que la carpeta fisica tenga permisos de ejecución/lectura
        chmod($targetFolder, 0755);
        $output[] = 'Permisos carpeta root public: 0755 verificado.';

        // Recorrer subcarpetas (whatsapp, media, etc)
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetFolder));

        foreach ($iterator as $item) {
            if ($item->getBasename() == '..') {
                continue;
            } // Saltar padre

            if ($item->isDir()) {
                chmod($item->getPathname(), 0755);
            } else {
                chmod($item->getPathname(), 0644);
            }
        }
        $output[] = '✅ Permisos corregidos recursivamente (Dir: 755, Files: 644).';

    } catch (Exception $e) {
        $output[] = '⚠️ No se pudieron cambiar todos los permisos: '.$e->getMessage();
    }

    return implode('<br>', $output);
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
        $info['cache_cleared'] = 'Cache limpiada (optimize:clear)';

        $testFile = 'whatsapp/media/test_debug.txt';
        $content = 'Prueba de escritura: '.now();

        $success = Storage::disk('public_uploads')->put($testFile, $content);

        if ($success) {
            // Check explicit path
            $correctPath = '/home/intesoga/whatsapp.integracolombia.online/whatsapp/media/test_debug.txt';
            $info['write_status'] = '✅ Éxito al escribir archivo';
            $info['file_check_correct_path'] = file_exists($correctPath) ? "✅ EXITOSO: Archivo encontrado en: $correctPath" : "❌ FALLÓ: Archivo NO encontrado en: $correctPath";

            // Check wrong path just in case
            $wrongPath = public_path($testFile);
            $info['file_check_wrong_path'] = file_exists($wrongPath) ? "⚠️ ADVERTENCIA: Archivo encontrado en la ruta interna (incorrecta): $wrongPath" : '✅ Correcto: Archivo NO está en la ruta interna';

            $info['url_generated'] = Storage::disk('public_uploads')->url($testFile);
        } else {
            $info['write_status'] = '❌ Falló la escritura (Storage::put retornó false)';
        }

    } catch (Exception $e) {
        $info['write_error'] = $e->getMessage();
    }

    return $info;
});

// Debug para diagnosticar columnas de base de datos directamente desde Laravel
Route::get('/debug-db-columns', function () {
    try {
        $columns = Schema::getColumnListing('whatsapp_messages');
        $dbName = DB::connection()->getDatabaseName();

        return response()->json([
            'database_name' => $dbName,
            'table_exists' => Schema::hasTable('whatsapp_messages'),
            'columns_laravel_sees' => $columns,
            'has_incoming_invoice_id' => in_array('incoming_invoice_id', $columns),
        ]);
    } catch (Exception $e) {
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

Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (auth()->attempt($credentials, $request->boolean('remember'))) {
        $request->session()->regenerate();

        $user = auth()->user();
        session(['company_id' => $user->company_id]);

        if ($user->hasRole('master')) {
            return redirect()->route('master.index');
        }

        // A la portada, no al chat: es donde se ve qué falta por configurar y
        // qué está sin responder. Quien venía siguiendo un enlace concreto
        // sigue yendo a donde iba, que es lo que respeta `intended`.
        return redirect()->intended('/');
    }

    return back()->withErrors([
        'email' => 'Las credenciales no coinciden.',
    ])->onlyInput('email');
});

// Recuperar la contraseña sin recordarla. El throttle es del formulario, además
// del que ya trae el broker por correo (config/auth.php): sin él, esta pantalla
// es un grifo para mandar correos a cualquier dirección de la plataforma.
Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [ContrasenaOlvidadaController::class, 'solicitar'])
        ->name('password.request');

    Route::post('/forgot-password', [ContrasenaOlvidadaController::class, 'enviarEnlace'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [ContrasenaOlvidadaController::class, 'formulario'])
        ->name('password.reset');

    Route::post('/reset-password', [ContrasenaOlvidadaController::class, 'restablecer'])
        ->middleware('throttle:6,1')
        ->name('password.update');
});

Route::post('/logout', function (Request $request) {
    auth()->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/login');
})->name('logout');

// Rutas protegidas
Route::middleware('auth')->group(function () {
    // La portada. Antes redirigía al chat, que dejaba al cliente recién
    // conectado ante una lista vacía sin decirle qué le faltaba por configurar.
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // El sistema de diseño, dentro del producto: pinta con los mismos tokens
    // que la aplicación, así que no puede documentar unos colores que ya no son.
    Route::get('/sistema-diseno', fn () => Inertia::render('SistemaDiseno'))
        ->name('sistema-diseno');
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::resource('instances', InstanceController::class)->only(['index', 'store', 'update', 'destroy']);

    // La guía de conexión por coexistencia, dentro del producto. Va antes que
    // /instances/{instance} para que "guia-coexistencia" no se tome por un id.
    Route::get('/instances/guia-coexistencia', fn () => Inertia::render('Instances/GuiaCoexistencia'))
        ->name('instances.guia-coexistencia');

    // Respaldo por consulta del progreso de la importación de coexistencia,
    // para cuando el websocket no conecta. Va antes de nada que capture
    // /instances/{algo} con otro significado.
    Route::get('/instances/{instance}/coexistence-sync', [InstanceController::class, 'coexistenceSync'])
        ->name('instances.coexistence-sync');
    // Genera la credencial de la API v1. Sólo quien puede editar la instancia:
    // el token deja enviar mensajes en nombre de la empresa.
    Route::post('/instances/{instance}/api-token', [InstanceController::class, 'generateApiToken'])
        ->middleware('permission:instances.update')
        ->name('instances.api-token');

    // Apagar una instancia en vez de borrarla, que es lo que casi siempre se
    // quiere: el número deja de enviar y de recibir y el historial se queda.
    Route::post('/instances/{instance}/desconectar', [InstanceController::class, 'desconectar'])
        ->middleware('permission:instances.update')->name('instances.desconectar');
    Route::post('/instances/{instance}/reconectar', [InstanceController::class, 'reconectar'])
        ->middleware('permission:instances.update')->name('instances.reconectar');

    // Lo que se perdería al borrarla, para poder decirlo en el diálogo con
    // números en vez de con un "¿estás seguro?".
    Route::get('/instances/{instance}/resumen-borrado', [InstanceController::class, 'resumenBorrado'])
        ->middleware('permission:instances.delete')->name('instances.resumen-borrado');

    // Registro insertado de Meta: conectar el WhatsApp del cliente sin pegar
    // tokens a mano. El GET sólo devuelve identificadores públicos; el POST es
    // el que crea la instancia, y por eso pide el mismo permiso que crearla.
    Route::get('/api/embedded-signup/config', [EmbeddedSignupController::class, 'config']);
    Route::post('/api/embedded-signup', [EmbeddedSignupController::class, 'store'])
        ->middleware('permission:instances.create');
    Route::get('/reports', [ReportsController::class, 'index'])
        ->middleware('permission:reports.view')->name('reports.index');

    Route::prefix('campaigns')->name('campaigns.')->group(function () {
        // Las rutas fijas van antes que /{campaign}: si no, "create" se toma por
        // el id de una campaña y la página del asistente devuelve un 404.
        Route::get('/contacts/search', [WhatsAppCampaignController::class, 'searchContacts'])
            ->middleware('permission:campaigns.view')->name('contacts.search');
        Route::get('/contacts/resolve', [WhatsAppCampaignController::class, 'resolveSelection'])
            ->middleware('permission:campaigns.view')->name('contacts.resolve');
        Route::get('/capacity', [WhatsAppCampaignController::class, 'capacity'])
            ->middleware('permission:campaigns.view')->name('capacity');
        Route::get('/templates', [WhatsAppCampaignController::class, 'templates'])
            ->middleware('permission:campaigns.view')->name('templates');
        Route::post('/template-media', [WhatsAppCampaignController::class, 'uploadTemplateMedia'])
            ->middleware('permission:campaigns.create')->name('template-media');
        Route::post('/segments', [WhatsAppCampaignController::class, 'storeSegment'])
            ->middleware('permission:campaigns.create')->name('segments.store');
        Route::delete('/segments/{segment}', [WhatsAppCampaignController::class, 'destroySegment'])
            ->middleware('permission:campaigns.create')->name('segments.destroy');
        Route::get('/create', [WhatsAppCampaignController::class, 'create'])
            ->middleware('permission:campaigns.create')->name('create');
        Route::get('/', [WhatsAppCampaignController::class, 'index'])
            ->middleware('permission:campaigns.view')->name('index');
        Route::get('/{campaign}', [WhatsAppCampaignController::class, 'show'])
            ->where('campaign', '[0-9]+')
            ->middleware('permission:campaigns.view')->name('show');
        Route::get('/{campaign}/progress', [WhatsAppCampaignController::class, 'progress'])
            ->middleware('permission:campaigns.view')->name('progress');
        Route::get('/{campaign}/export', [WhatsAppCampaignController::class, 'export'])
            ->middleware('permission:campaigns.view')->name('export');
        Route::post('/', [WhatsAppCampaignController::class, 'store'])
            ->middleware('permission:campaigns.create')->name('store');
        Route::post('/{campaign}/send', [WhatsAppCampaignController::class, 'send'])
            ->middleware('permission:campaigns.update')->name('send');
        Route::post('/{campaign}/pause', [WhatsAppCampaignController::class, 'pause'])
            ->middleware('permission:campaigns.update')->name('pause');
        Route::post('/{campaign}/resume', [WhatsAppCampaignController::class, 'resume'])
            ->middleware('permission:campaigns.update')->name('resume');
        Route::post('/{campaign}/cancel', [WhatsAppCampaignController::class, 'cancel'])
            ->middleware('permission:campaigns.update')->name('cancel');
        Route::post('/{campaign}/retry-failed', [WhatsAppCampaignController::class, 'retryFailed'])
            ->middleware('permission:campaigns.update')->name('retry-failed');
        Route::delete('/{campaign}', [WhatsAppCampaignController::class, 'destroy'])
            ->middleware('permission:campaigns.delete')->name('destroy');
    });

    Route::prefix('templates')->name('templates.')->group(function () {
        Route::get('/', [TemplateController::class, 'index'])
            ->middleware('permission:templates.view')->name('index');
        Route::get('/analytics', [TemplateController::class, 'analyticsIndex'])
            ->middleware('permission:templates.view')->name('analytics');
        Route::get('/create', [TemplateController::class, 'create'])
            ->middleware('permission:templates.create')->name('create');
        Route::get('/defaults', [TemplateController::class, 'defaultsIndex'])
            ->middleware('permission:templates.view')->name('defaults');
    });

    Route::prefix('api/templates')->group(function () {
        Route::get('/', [TemplateController::class, 'list'])
            ->middleware('permission:templates.view');
        // Copiar plantillas de una línea a otra: los catálogos son por WABA,
        // así que dos líneas de la misma empresa no comparten ninguna.
        Route::post('/duplicar', [TemplateController::class, 'duplicar'])
            ->middleware('permission:templates.create');
        Route::get('/analytics', [TemplateController::class, 'analytics'])
            ->middleware('permission:templates.view');
        Route::get('/analytics/conversations', [TemplateController::class, 'conversationAnalytics'])
            ->middleware('permission:templates.view');
        Route::post('/analytics/enable', [TemplateController::class, 'enableInsights'])
            ->middleware('permission:templates.view');
        Route::get('/defaults', [TemplateController::class, 'defaults'])
            ->middleware('permission:templates.view');
        Route::post('/defaults/{key}/sync', [TemplateController::class, 'syncDefault'])
            ->where('key', '[a-z0-9_]+')
            ->middleware('permission:templates.create');
        Route::get('/family/{name}', [TemplateController::class, 'family'])
            ->where('name', '[A-Za-z0-9_\-\.]+')
            ->middleware('permission:templates.view');
        Route::get('/{templateId}', [TemplateController::class, 'show'])
            ->where('templateId', '[0-9]+')
            ->middleware('permission:templates.view');
        Route::post('/upload-media', [TemplateController::class, 'uploadMedia'])
            ->middleware('permission:templates.create');
        Route::post('/', [TemplateController::class, 'store'])
            ->middleware('permission:templates.create');
    });

    Route::prefix('auto-responses')->name('auto-responses.')->group(function () {
        Route::get('/', [AutoResponseController::class, 'index'])
            ->middleware('permission:auto_responses.view')->name('index');
        Route::post('/', [AutoResponseController::class, 'store'])
            ->middleware('permission:auto_responses.create')->name('store');
        Route::put('/{auto_response}', [AutoResponseController::class, 'update'])
            ->middleware('permission:auto_responses.update')->name('update');
        Route::delete('/{auto_response}', [AutoResponseController::class, 'destroy'])
            ->middleware('permission:auto_responses.delete')->name('destroy');
    });
    // Menús interactivos de WhatsApp (botones y listas)
    Route::prefix('whatsapp-menus')->name('whatsapp-menus.')->group(function () {
        Route::get('/', [WhatsAppMenuController::class, 'index'])
            ->middleware('permission:whatsapp_menus.view')->name('index');
        Route::post('/', [WhatsAppMenuController::class, 'store'])
            ->middleware('permission:whatsapp_menus.create')->name('store');
        Route::put('/{menu}', [WhatsAppMenuController::class, 'update'])
            ->middleware('permission:whatsapp_menus.update')->name('update');
        Route::delete('/{menu}', [WhatsAppMenuController::class, 'destroy'])
            ->middleware('permission:whatsapp_menus.delete')->name('destroy');
        // El interruptor de la IA de los menús.
        Route::post('/ai', [WhatsAppMenuController::class, 'toggleAi'])
            ->middleware('permission:whatsapp_menus.update')->name('ai');
        // Hasta dónde llega la IA de esta empresa. Aparte del interruptor
        // porque son decisiones distintas: encenderla no puede significar
        // autorizarle radicados y cobros de una vez.
        Route::post('/ai/permisos', [WhatsAppMenuController::class, 'updateAiPermissions'])
            ->middleware('permission:whatsapp_menus.update')->name('ai.permissions');
        // Catálogos de Integra (tipos de falla, prioridades, técnicos) para el
        // formulario. Va aparte de index porque es una llamada HTTP a otro
        // servidor: si Integra tarda, no debe retrasar la carga de la página.
        Route::get('/integra-catalogs', [WhatsAppMenuController::class, 'integraCatalogs'])
            ->middleware('permission:whatsapp_menus.view')->name('integra-catalogs');
        // Qué le va a fallar al menú antes de que lo toque un cliente. Aparte de
        // index() por lo mismo: comprueba los permisos reales del token contra
        // el servidor de Integra.
        Route::get('/revision', [WhatsAppMenuController::class, 'review'])
            ->middleware('permission:whatsapp_menus.view')->name('revision');
        // Sube la imagen de una opción y devuelve su URL pública. Se sube al
        // elegir el archivo y no al guardar el menú: Meta descarga la imagen
        // desde esa URL al enviar, así que el admin tiene que poder verla antes
        // de encender el menú.
        Route::post('/imagen', [WhatsAppMenuController::class, 'uploadImage'])
            ->middleware('permission:whatsapp_menus.update')->name('imagen');
    });

    Route::resource('users', UserController::class)->middleware('permission:users.view');
    Route::resource('roles', RoleController::class)->middleware('permission:roles.view');
    Route::get('/kanban', [ChatController::class, 'kanban'])->name('chat.kanban');

    // Quick Replies (Respuestas Rápidas) — admin page
    Route::get('/quick-replies', [QuickReplyController::class, 'index'])
        ->middleware('permission:quick_replies.view')
        ->name('quick-replies.index');

    // Macros — admin page
    Route::get('/macros', [MacroController::class, 'index'])
        ->middleware('permission:macros.view')
        ->name('macros.index');

    // Contactos — admin page
    Route::get('/contactos', [ContactController::class, 'index'])
        ->middleware('permission:contacts.view')
        ->name('contacts.index');

    // Rutas Master
    Route::prefix('master')->name('master.')->group(function () {
        Route::get('/', [MasterController::class, 'index'])->name('index');
        Route::post('/companies', [MasterController::class, 'store'])->name('companies.store');
        Route::put('/companies/{company}', [MasterController::class, 'update'])->name('companies.update');
        Route::post('/impersonate/{company}', [MasterController::class, 'impersonate'])->name('impersonate');

        // Restablecer la contraseña de cualquier usuario de cualquier empresa,
        // para cuando quien se ha quedado fuera es el propio admin del cliente
        // y no hay a quién pedírselo.
        Route::post('/users/{user}/password', [MasterController::class, 'resetUserPassword'])
            ->name('users.password');

        // Mensajes no entregados de todas las empresas (auditoría + reintento)
        Route::prefix('messages')->name('messages.')->group(function () {
            Route::get('/', [MessagesController::class, 'index'])->name('index');
            Route::get('/{message}', [MessagesController::class, 'show'])
                ->whereNumber('message')->name('show');
            Route::get('/{message}/media', [MessagesController::class, 'media'])
                ->whereNumber('message')->name('media');
            Route::post('/{message}/retry', [MessagesController::class, 'retry'])
                ->whereNumber('message')->name('retry');
        });

        Route::prefix('logs')->name('logs.')->group(function () {
            Route::get('/', [LogsController::class, 'index'])->name('index');
            Route::get('/{file}', [LogsController::class, 'show'])
                ->where('file', '[A-Za-z0-9_.\-]+')->name('show');
            Route::delete('/{file}', [LogsController::class, 'destroy'])
                ->where('file', '[A-Za-z0-9_.\-]+')->name('destroy');
            Route::post('/{file}/clear', [LogsController::class, 'clear'])
                ->where('file', '[A-Za-z0-9_.\-]+')->name('clear');
        });
    });

    Route::post('/stop-impersonating', [MasterController::class, 'stopImpersonating'])->name('stop-impersonating');

    // Settings routes
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::put('/profile', [SettingsController::class, 'updateProfile'])->name('profile');
        Route::put('/password', [SettingsController::class, 'updatePassword'])->name('password');
        Route::delete('/sessions', [SettingsController::class, 'destroyOtherSessions'])->name('sessions.destroy');
    });

    // Flujo IA: el apartado va detrás de un secreto, así que el desbloqueo se
    // limita —el secreto es corto y se puede probar a ciegas—. El resto sólo
    // exige el permiso de siempre.
    Route::prefix('api/settings/ai-flow')->group(function () {
        Route::get('/', [AiFlowSettingsController::class, 'show'])
            ->middleware('permission:whatsapp_menus.update');
        Route::post('/unlock', [AiFlowSettingsController::class, 'unlock'])
            ->middleware(['permission:whatsapp_menus.update', 'throttle:5,1']);
        Route::delete('/unlock', [AiFlowSettingsController::class, 'lock'])
            ->middleware('permission:whatsapp_menus.update');
        Route::put('/', [AiFlowSettingsController::class, 'update'])
            ->middleware('permission:whatsapp_menus.update');
    });

    Route::prefix('api/business-hours')->group(function () {
        Route::get('/', [BusinessHourController::class, 'index'])
            ->middleware('permission:business_hours.view');
        Route::post('/', [BusinessHourController::class, 'store'])
            ->middleware('permission:business_hours.create');
        Route::put('/{id}', [BusinessHourController::class, 'update'])
            ->middleware('permission:business_hours.update');
        Route::delete('/{id}', [BusinessHourController::class, 'destroy'])
            ->middleware('permission:business_hours.delete');
    });

    // WhatsApp settings API (readiness checklist + activations + profile)
    Route::prefix('api/settings/whatsapp')->group(function () {
        Route::get('/instances', [WhatsAppSettingsController::class, 'instances']);
        Route::get('/readiness', [WhatsAppSettingsController::class, 'readiness']);
        Route::get('/phone-numbers', [WhatsAppSettingsController::class, 'phoneNumbers']);
        Route::get('/profile', [WhatsAppSettingsController::class, 'getProfile']);
        Route::post('/subscribe-webhook', [WhatsAppSettingsController::class, 'subscribeWebhook'])
            ->middleware('permission:instances.update');
        Route::post('/register-number', [WhatsAppSettingsController::class, 'registerNumber'])
            ->middleware('permission:instances.update');
        Route::post('/request-code', [WhatsAppSettingsController::class, 'requestCode'])
            ->middleware('permission:instances.update');
        Route::post('/verify-code', [WhatsAppSettingsController::class, 'verifyCode'])
            ->middleware('permission:instances.update');
        Route::post('/enable-insights', [WhatsAppSettingsController::class, 'enableInsights'])
            ->middleware('permission:instances.update');
        Route::post('/profile', [WhatsAppSettingsController::class, 'updateProfile'])
            ->middleware('permission:instances.update');
        Route::post('/profile/photo', [WhatsAppSettingsController::class, 'updateProfilePhoto'])
            ->middleware('permission:instances.update');

        // Configuración de llamadas: estado, habilitar función y toggle de salientes
        Route::get('/calling', [WhatsAppSettingsController::class, 'callingSettings']);
        Route::post('/calling/enable', [WhatsAppSettingsController::class, 'enableCalling'])
            ->middleware('permission:instances.update');
        Route::post('/calling', [WhatsAppSettingsController::class, 'updateCallingSettings'])
            ->middleware('permission:instances.update');

        // Plantilla de reinicio de conversación (reabrir chats fuera de la ventana de 24h)
        Route::get('/resume-template', [WhatsAppSettingsController::class, 'resumeTemplateSettings']);
        Route::post('/resume-template', [WhatsAppSettingsController::class, 'updateResumeTemplate'])
            ->middleware('permission:instances.update');

        // Plantilla de respaldo de los avisos automáticos fuera de la ventana de 24h
        Route::get('/fallback-template', [WhatsAppSettingsController::class, 'fallbackTemplateSettings']);
        Route::post('/fallback-template', [WhatsAppSettingsController::class, 'updateFallbackTemplate'])
            ->middleware('permission:instances.update');
        Route::post('/fallback-template/provision', [WhatsAppSettingsController::class, 'provisionFallbackTemplate'])
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
        Route::get('/messages/{messageId}/media', [ChatController::class, 'downloadMedia']);

        // Vista imprimible del hilo: se abre en pestaña nueva y el navegador
        // la guarda como PDF.
        Route::get('/conversations/{conversationId}/export', [ChatController::class, 'exportPrintable'])
            ->name('chat.conversations.export');

        // Acciones sobre un mensaje concreto (menú de la burbuja)
        Route::post('/messages/{messageId}/react', [ChatController::class, 'reactToMessage']);
        Route::post('/messages/{messageId}/forward', [ChatController::class, 'forwardMessage']);
        Route::put('/messages/{messageId}/note', [ChatController::class, 'updateNote']);
        Route::put('/messages/{messageId}/content', [ChatController::class, 'editSentMessage']);
        Route::get('/updates', [ChatController::class, 'updates']);
        Route::post('/conversations/{conversationId}/send', [ChatController::class, 'sendMessage']);
        Route::post('/conversations/{conversationId}/send-template', [ChatController::class, 'sendTemplate']);
        Route::post('/conversations/{conversationId}/template-media', [ChatController::class, 'uploadTemplateMedia']);
        Route::post('/conversations/{conversationId}/note', [ChatController::class, 'storeNote']);
        Route::post('/conversations/{conversationId}/send-image', [ChatController::class, 'sendImage']);
        Route::post('/conversations/{conversationId}/send-document', [ChatController::class, 'sendDocument']);
        Route::post('/conversations/{conversationId}/send-audio', [ChatController::class, 'sendAudio']);
        Route::post('/conversations/{conversationId}/close', [ChatController::class, 'close']);
        Route::post('/conversations/{conversationId}/reopen', [ChatController::class, 'reopen']);
        Route::delete('/conversations/{conversationId}', [ChatController::class, 'destroy']);

        // Peticiones de eliminación: sin chat.delete el borrado se convierte en
        // una petición que resuelve alguien que sí lo tiene.
        Route::get('/deletion-requests', [ChatController::class, 'deletionRequests']);
        Route::post('/deletion-requests/{requestId}/resolve', [ChatController::class, 'resolveDeletionRequest']);
        Route::post('/conversations/{conversationId}/assign', [ChatController::class, 'assign'])->middleware('permission:chat.update');
        Route::post('/conversations/{conversationId}/assign-me', [ChatController::class, 'assignToMe']);
        Route::post('/conversations/{conversationId}/attach-contact', [ContactController::class, 'attachConversation'])->middleware('permission:contacts.view');
        Route::get('/users', [UserController::class, 'getCompanyUsers']);

        // Llamadas WhatsApp (Fase 2: permiso para salientes)
        Route::post('/conversations/{conversationId}/call-permission', [CallController::class, 'requestPermission']);
        Route::get('/conversations/{conversationId}/call-permission', [CallController::class, 'permissionStatus']);

        // Historial de llamadas
        Route::get('/calls', [CallController::class, 'history']);
        Route::get('/conversations/{conversationId}/calls', [CallController::class, 'conversationHistory']);
    });

    // Contacts API routes
    Route::prefix('api/contacts')->group(function () {
        Route::get('/list', [ContactController::class, 'list'])->middleware('permission:contacts.view');
        Route::post('/', [ContactController::class, 'store'])->middleware('permission:contacts.create');
        Route::put('/{contact}', [ContactController::class, 'update'])->middleware('permission:contacts.update');
        Route::delete('/{contact}', [ContactController::class, 'destroy'])->middleware('permission:contacts.delete');
        Route::post('/{contact}/opt-out', [ContactController::class, 'toggleOptOut'])
            ->middleware('permission:contacts.update')->name('contacts.opt-out');
        Route::post('/opt-out-requests/{conversation}', [ContactController::class, 'resolveOptOutRequest'])
            ->middleware('permission:contacts.update')->name('contacts.opt-out-request');
    });

    // Kanban API routes
    Route::prefix('api/kanban')->group(function () {
        Route::get('/columns', [KanbanController::class, 'columns']);
        Route::get('/counts', [KanbanController::class, 'columnCounts']);
        Route::get('/contactos', [KanbanController::class, 'contactos']);
        Route::post('/columns', [KanbanController::class, 'storeColumn']);
        Route::put('/columns/{id}', [KanbanController::class, 'updateColumn']);
        Route::delete('/columns/{id}', [KanbanController::class, 'deleteColumn']);
        Route::get('/columns/{id}/cards', [KanbanController::class, 'columnCards']);
        Route::post('/conversations/{id}/move', [KanbanController::class, 'moveCard']);
        Route::post('/cards', [KanbanController::class, 'storeCard']);
    });

    // Quick Replies API routes
    Route::prefix('api/quick-replies')->group(function () {
        Route::get('/', [QuickReplyController::class, 'list']);
        Route::post('/', [QuickReplyController::class, 'store'])->middleware('permission:quick_replies.create');
        Route::put('/{quickReply}', [QuickReplyController::class, 'update'])->middleware('permission:quick_replies.update');
        Route::delete('/{quickReply}', [QuickReplyController::class, 'destroy'])->middleware('permission:quick_replies.delete');
    });

    // Macros API routes
    Route::prefix('api/macros')->group(function () {
        Route::get('/', [MacroController::class, 'list']);
        Route::post('/', [MacroController::class, 'store'])->middleware('permission:macros.create');
        Route::put('/{macro}', [MacroController::class, 'update'])->middleware('permission:macros.update');
        Route::delete('/{macro}', [MacroController::class, 'destroy'])->middleware('permission:macros.delete');
        Route::post('/{macro}/run/{conversationId}', [MacroController::class, 'run'])->middleware('permission:macros.run');
    });

    // Tag API routes
    Route::prefix('api/tags')->group(function () {
        Route::get('/', [TagController::class, 'index']);
        Route::post('/', [TagController::class, 'store']);
        Route::put('/{tag}', [TagController::class, 'update']);
        Route::delete('/{tag}', [TagController::class, 'destroy']);
        Route::post('/conversations/{id}/attach', [TagController::class, 'attachToConversation']);
        Route::post('/conversations/{id}/detach', [TagController::class, 'detachFromConversation']);
    });

    // In-app notifications (mentions + system announcements bell)
    Route::prefix('api/notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/{id}/read', [NotificationController::class, 'markRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
        Route::delete('/', [NotificationController::class, 'destroyAll']);
    });

    // System announcements (admin-emitted notifications)
    Route::get('/announcements', [SystemNotificationController::class, 'index'])
        ->middleware('permission:notifications.send')->name('announcements.index');
    Route::prefix('api/announcements')->middleware('permission:notifications.send')->group(function () {
        Route::get('/', [SystemNotificationController::class, 'history']);
        Route::post('/', [SystemNotificationController::class, 'store']);
    });

    // Integraciones — Webhooks salientes (parametrizables por empresa)
    Route::get('/integrations', [WebhookEndpointController::class, 'index'])
        ->middleware('permission:integrations.view')->name('integrations.index');
    Route::post('/integrations/linea-erp', [WebhookEndpointController::class, 'elegirLineaDelErp'])
        ->middleware('permission:integrations.create')->name('integrations.linea-erp');
    // Los ajustes de envío del ERP: se leen y se escriben en Integra, no aquí.
    Route::get('/integrations/ajustes-envio', [WebhookEndpointController::class, 'ajustesDeEnvio'])
        ->middleware('permission:integrations.view');
    Route::put('/integrations/ajustes-envio', [WebhookEndpointController::class, 'guardarAjustesDeEnvio'])
        ->middleware('permission:integrations.create');
    // Qué dato del ERP va en cada variable de la plantilla. Misma historia:
    // se edita aquí, al lado de la plantilla, y se guarda en Integra.
    Route::get('/integrations/plantillas/{plantilla}/campos', [WebhookEndpointController::class, 'camposDePlantilla'])
        ->whereNumber('plantilla')->middleware('permission:integrations.view');
    Route::put('/integrations/plantillas/{plantilla}/campos', [WebhookEndpointController::class, 'guardarCamposDePlantilla'])
        ->whereNumber('plantilla')->middleware('permission:integrations.create');
    Route::prefix('api/webhooks')->group(function () {
        Route::get('/', [WebhookEndpointController::class, 'list'])
            ->middleware('permission:integrations.view');
        Route::post('/', [WebhookEndpointController::class, 'store'])
            ->middleware('permission:integrations.create');
        // Prueba una dirección antes de guardarla, para no crear un webhook
        // que nunca va a entregar.
        Route::post('/probe', [WebhookEndpointController::class, 'probe'])
            ->middleware('permission:integrations.create');
        Route::put('/{webhook}', [WebhookEndpointController::class, 'update'])
            ->middleware('permission:integrations.update');
        Route::delete('/{webhook}', [WebhookEndpointController::class, 'destroy'])
            ->middleware('permission:integrations.delete');
        Route::post('/{webhook}/test', [WebhookEndpointController::class, 'test'])
            ->middleware('permission:integrations.update');
        Route::get('/{webhook}/deliveries', [WebhookEndpointController::class, 'deliveries'])
            ->middleware('permission:integrations.view');
    });

    // Integraciones — Pagos a facturas (software Integra, API V1 con token maestro)
    Route::prefix('api/integrations')->group(function () {
        Route::get('/', [IntegrationController::class, 'index'])
            ->middleware('permission:integrations.view');
        Route::post('/{key}/connect', [IntegrationController::class, 'connect'])
            ->middleware('permission:integrations.update');
        Route::get('/{key}/status', [IntegrationController::class, 'status'])
            ->middleware('permission:integrations.view');
        Route::post('/{key}/activate', [IntegrationController::class, 'activate'])
            ->middleware('permission:integrations.update');
        Route::post('/{key}/disconnect', [IntegrationController::class, 'disconnect'])
            ->middleware('permission:integrations.update');
        Route::post('/{key}/sync', [IntegrationController::class, 'syncContacts'])
            ->middleware('permission:integrations.update');
        Route::get('/{key}/sync-status', [IntegrationController::class, 'syncStatus'])
            ->middleware('permission:integrations.view');

        // Acciones usadas desde el chat por los agentes (solo requieren sesión).
        Route::get('/invoice-payments/clients', [IntegrationController::class, 'searchClients']);
        Route::get('/invoice-payments/invoices', [IntegrationController::class, 'invoices']);
        Route::get('/invoice-payments/catalogs', [IntegrationController::class, 'catalogs']);
        Route::post('/invoice-payments/pay', [IntegrationController::class, 'pay']);
    });
});
