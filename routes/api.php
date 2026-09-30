<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;

// Routes moved to web.php to share session state
// But we keep this api.php for external integrations as requested

use App\Http\Controllers\Api\MessageApiController;

Route::prefix('v1')->group(function () {
    Route::post('/messages/send', [MessageApiController::class, 'sendMessage']);
    Route::post('/messages/template', [MessageApiController::class, 'sendTemplate']);
    Route::post('/messages/document', [MessageApiController::class, 'sendDocument']);
    Route::post('/messages/register', [MessageApiController::class, 'registerMessage']);
    // Lo que el ERP pregunta antes de enviar: por qué línea le toca hacerlo.
    Route::get('/config', [MessageApiController::class, 'config']);
    Route::get('/whatsapp-messages', [MessageApiController::class, 'getWhatsAppMessages']);
    Route::get('/conversations', [MessageApiController::class, 'getConversations']);
    Route::get('/conversations/{id}/messages', [MessageApiController::class, 'getMessages']);
});

// Pass-through al MCP de Integra: el servidor NO está aquí, está dentro de la
// instancia de cada cliente. Esto sólo enruta por empresa, porque las
// credenciales de n8n son estáticas y lo único que admite expresión es la URL
// del nodo (ver McpGrant y docs/mcp-integra.md).
Route::post('/mcp/integra/{grant}', [\App\Http\Controllers\Mcp\IntegraMcpProxyController::class, 'handle'])
    ->where('grant', '[A-Za-z0-9_\-]+');
