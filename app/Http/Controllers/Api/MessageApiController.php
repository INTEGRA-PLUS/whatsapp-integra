<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;
use App\Services\TemplateParameterGuard;
use App\Services\WhatsAppFallbackTemplateService;
use Carbon\Carbon;

class MessageApiController extends Controller
{
    private $metaService;

    public function __construct(
        MetaWhatsAppService $metaService,
        private WhatsAppFallbackTemplateService $fallbackTemplates,
        private TemplateParameterGuard $templateGuard
    ) {
        $this->metaService = $metaService;
    }

    /**
     * Instancia dueña del token, o una respuesta de error.
     *
     * El índice único de `instances` es (company_id, phone_number_id), no
     * phone_number_id a secas: dos empresas activas pueden reclamar el mismo
     * número. Cuando pasa, este token no identifica a nadie —`first()` se
     * quedaba con la de id más bajo, en silencio— y el aviso se atribuye a la
     * empresa equivocada: se guarda en su hilo y, desde que existe la plantilla
     * de respaldo, sale firmado con su nombre. El cliente de ABC leería un
     * aviso de CBA. Una credencial ambigua no autentica: se rechaza y se avisa
     * para que un administrador desduplique.
     */
    private function validateInstance(Request $request)
    {
        $token = $request->header('X-Instance-Token');

        if (!$token) {
            return null;
        }

        $candidates = Instance::where('phone_number_id', $token)
            ->where('active', true)
            ->orderBy('id')
            ->get();

        if ($candidates->count() > 1) {
            Log::channel('whatsapp')->error('🚨 Token de API ambiguo: phone_number_id duplicado en varias empresas activas', [
                'phone_number_id' => $token,
                'instances' => $candidates->pluck('company_id', 'id'),
            ]);

            return response()->json([
                'error' => 'Este phone_number_id está registrado como activo en más de una empresa, '
                    . 'así que no identifica de forma única quién envía. Un administrador debe '
                    . 'desactivar las instancias duplicadas antes de seguir enviando.',
                'code' => 'ambiguous_instance',
            ], 409);
        }

        return $candidates->first();
    }

    public function sendMessage(Request $request)
    {
        $instance = $this->validateInstance($request);
        if ($instance instanceof \Illuminate\Http\JsonResponse) {
            return $instance;
        }
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $validator = Validator::make($request->all(), [
            'to' => 'required|string',
            'message' => 'required|string|max:4096',
            'incoming_invoice_id' => 'nullable|integer',
            'incoming_contract_id' => 'nullable|integer',
            'incoming_payment_id' => 'nullable|integer',
            'incoming_company_nit' => 'nullable|integer',
            'template_id' => 'nullable|integer',
            // Plantilla de respaldo: si el destinatario está fuera de la ventana
            // de 24h se envía ésta en vez de perder el aviso. Quien llama no
            // tiene que saber en qué lado de la ventana está cada cliente.
            'template_name' => 'nullable|string',
            'language_code' => 'nullable|string',
            'components' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // El destinatario se normaliza aquí, en la frontera: el sistema externo lo
        // manda como lo tenga guardado ("57300 825 3303", "+57 300...") y así
        // llegaba tal cual al hilo y a WhatsApp. El hilo escrito de otra forma
        // que el que abre el webhook partía la conversación en dos. Un BSUID pasa
        // intacto: quitarle las letras lo convertiría en un teléfono inventado.
        $to = WhatsAppConversation::normalizeRecipient($request->to);

        if ($to === '') {
            return response()->json(['errors' => ['to' => [
                'El destinatario debe ser un número de teléfono o un identificador de WhatsApp (por ejemplo CO.1402615141764490).',
            ]]], 422);
        }

        $messageContent = $request->message;

        // Find or create conversation
        $conversation = WhatsAppConversation::resolveFor(
            $instance->id,
            $to,
            [
                'phone_number' => $to,
                'name' => $to, // Fallback to phone number
                'status' => 'open',
                'last_message_at' => now()
            ]
        );

        // Fuera de la ventana de 24h WhatsApp solo acepta plantillas aprobadas.
        // Con texto libre Meta responde 200 y devuelve wamid, y sólo después
        // avisa por webhook de que falló: quien llama se queda creyendo que el
        // aviso salió y el cliente final nunca lo recibe.
        $windowClosed = !$conversation->isWindowOpen();

        // Camino bueno: si nos dieron una plantilla de respaldo, el aviso sale
        // como plantilla en vez de morir. Es lo que convierte una notificación
        // perdida en una entregada, y evita que quien llama tenga que llevar la
        // cuenta de las 24h de cada cliente.
        if ($windowClosed && $request->filled('template_name')) {
            Log::channel('whatsapp')->info('🔁 Ventana de 24h cerrada: se envía la plantilla de respaldo', [
                'company_id' => $instance->company_id,
                'conversation_id' => $conversation->id,
                'template' => $request->template_name,
            ]);

            return $this->sendTemplate($request);
        }

        // Sin plantilla en la llamada, el respaldo lo pone la propia instancia:
        // el texto del ERP se envuelve en la plantilla aprobada de esa empresa,
        // que el servicio crea en su WABA la primera vez que hace falta. Es lo
        // que evita tener que tocar el ERP aviso por aviso.
        if ($windowClosed) {
            try {
                $fallback = $this->fallbackTemplates->prepare($instance, $messageContent, $conversation);
            } catch (\Throwable $e) {
                // El respaldo es una mejora sobre el camino de siempre, no un
                // requisito suyo: si se cae, el envío sigue como sigue hoy.
                Log::channel('whatsapp')->error('❌ Error resolviendo la plantilla de respaldo', [
                    'company_id' => $instance->company_id,
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);

                $fallback = ['ok' => false, 'status' => null, 'reason' => 'exception', 'name' => null];
            }

            if ($fallback['ok']) {
                return $this->deliverFallbackTemplate($request, $instance, $conversation, $to, $messageContent, $fallback);
            }

            Log::channel('whatsapp')->warning('🕓 Ventana de 24h cerrada y sin plantilla de respaldo utilizable', [
                'company_id' => $instance->company_id,
                'conversation_id' => $conversation->id,
                'template' => $fallback['name'],
                'template_status' => $fallback['status'],
                'reason' => $fallback['reason'],
            ]);
        }

        if ($windowClosed && $this->windowGuardEnforced($instance)) {
            return response()->json([
                'success' => false,
                'code' => 'window_closed',
                'template_status' => $fallback['status'] ?? null,
                'error' => 'El destinatario no escribe desde hace más de 24 horas. '
                    . 'WhatsApp no permite texto libre fuera de esa ventana. '
                    . $this->fallbackHint($fallback ?? null)
                    . ' También puedes añadir "template_name" (y sus "components") a esta '
                    . 'misma llamada, o usar POST /api/v1/messages/template.',
            ], 422);
        }

        if ($windowClosed) {
            // Modo sombra: se deja pasar exactamente como hasta ahora para no
            // llenar de errores al ERP de un día para otro. El envío casi con
            // seguridad morirá con "Re-engagement", pero queda marcado para
            // poder medir el volumen y comprobar que el guardarraíl no tiene
            // falsos positivos antes de encenderlo.
            Log::channel('whatsapp')->warning('🕓 Ventana de 24h cerrada: envío dejado pasar en modo sombra', [
                'company_id' => $instance->company_id,
                'conversation_id' => $conversation->id,
                'template_status' => $fallback['status'] ?? null,
            ]);
        }

        $result = $this->metaService->sendMessage(
            $instance->phone_number_id,
            $to,
            $messageContent
        );

        if ($result['success']) {
            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => $result['data']['messages'][0]['id'],
                'type' => 'text',
                'content' => $messageContent,
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_at' => now(),
                'incoming_invoice_id' => $request->incoming_invoice_id,
                'incoming_contract_id' => $request->incoming_contract_id,
                'incoming_payment_id' => $request->incoming_payment_id,
                'incoming_company_nit' => $request->incoming_company_nit,
                'template_id' => $request->template_id,
                // La marca hace que el informe sea una consulta a la BD y no un
                // parseo de logs, y permite cruzarla con el estado final para
                // saber si el guardarraíl habría acertado. El estado de la
                // plantilla explica por qué el respaldo no pudo rescatarlo.
                'metadata' => $windowClosed ? [
                    'window_guard' => 'shadow_pass',
                    'fallback_status' => $fallback['status'] ?? null,
                    'fallback_reason' => $fallback['reason'] ?? null,
                ] : null,
            ]);

            $conversation->update([
                'last_message' => $messageContent,
                'last_message_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message_id' => $message->id,
                'wamid' => $message->wamid
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error']['error']['message'] ?? 'Error al enviar a Meta'
        ], 500);
    }

    public function sendTemplate(Request $request)
    {
        $instance = $this->validateInstance($request);
        if ($instance instanceof \Illuminate\Http\JsonResponse) {
            return $instance;
        }
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $validator = Validator::make($request->all(), [
            'to' => 'required|string',
            'template_name' => 'required|string',
            'language_code' => 'nullable|string',
            'components' => 'nullable|array',
            'incoming_invoice_id' => 'nullable|integer',
            'incoming_contract_id' => 'nullable|integer',
            'incoming_payment_id' => 'nullable|integer',
            'incoming_company_nit' => 'nullable|integer',
            'template_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // El destinatario se normaliza aquí, en la frontera: el sistema externo lo
        // manda como lo tenga guardado ("57300 825 3303", "+57 300...") y así
        // llegaba tal cual al hilo y a WhatsApp. El hilo escrito de otra forma
        // que el que abre el webhook partía la conversación en dos. Un BSUID pasa
        // intacto: quitarle las letras lo convertiría en un teléfono inventado.
        $to = WhatsAppConversation::normalizeRecipient($request->to);

        if ($to === '') {
            return response()->json(['errors' => ['to' => [
                'El destinatario debe ser un número de teléfono o un identificador de WhatsApp (por ejemplo CO.1402615141764490).',
            ]]], 422);
        }

        $templateName = $request->template_name;
        $languageCode = $request->language_code ?? 'es';
        $components = $request->components ?? [];

        // Find or create conversation
        $conversation = WhatsAppConversation::resolveFor(
            $instance->id,
            $to,
            [
                'phone_number' => $to,
                'name' => $to,
                'status' => 'open',
                'last_message_at' => now()
            ]
        );

        // Guardarraíl: un encabezado que Meta no pueda resolver se acepta con un
        // 200 y muere después por webhook, así que quien llama nunca se entera de
        // que el aviso no llegó. Aquí se entera ahora, con el motivo concreto.
        $guard = $this->templateGuard->check($instance, $templateName, $languageCode, $components);

        if (!$guard['ok']) {
            Log::channel('whatsapp')->warning('⛔ Plantilla rechazada antes de llegar a Meta', [
                'company_id'   => $instance->company_id,
                'conversation_id' => $conversation->id,
                'template'     => $templateName,
                'guard_code'   => $guard['code'],
                'guard_error'  => $guard['error'],
            ]);

            return response()->json([
                'success' => false,
                'code'    => TemplateParameterGuard::CODE,
                'reason'  => $guard['code'],
                'error'   => $guard['error'],
            ], 422);
        }

        $components = $guard['components'];

        $result = $this->metaService->sendTemplate(
            $instance->phone_number_id,
            $to,
            $templateName,
            $languageCode,
            $components
        );

        if ($result['success']) {
            // Plantillas con encabezado multimedia: guardamos una copia del
            // adjunto para que el chat pueda mostrarlo y descargarlo, no solo el
            // texto. El media_id queda persistido aunque la descarga falle, para
            // poder reintentarla al abrir el mensaje.
            $mediaMetadata = ['components' => $components];
            $headerMediaId = $this->headerMediaId($mediaMetadata);
            $mediaUrl = null;
            $filename = $this->headerFilename($mediaMetadata);
            $mediaMimeType = null;

            if ($headerMediaId && !empty($instance->access_token)) {
                $mediaInfo = $this->metaService->downloadMedia($headerMediaId, $instance->access_token);
                if ($mediaInfo) {
                    $mediaUrl = $mediaInfo['url'];
                    $filename = $filename ?: $mediaInfo['filename'];
                    $mediaMimeType = $mediaInfo['mime_type'];
                }
            }

            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => $result['data']['messages'][0]['id'],
                // Solo las plantillas con adjunto necesitan la burbuja de
                // plantilla; las de texto plano se siguen viendo como texto.
                'type' => $headerMediaId ? 'template' : 'text',
                'content' => "[Plantilla: $templateName]",
                'media_url' => $mediaUrl,
                'media_id' => $headerMediaId,
                'media_mime_type' => $mediaMimeType,
                'filename' => $filename,
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_at' => now(),
                'metadata' => [
                    'template' => $templateName,
                    'language' => $languageCode,
                    'components' => $components
                ],
                'incoming_invoice_id' => $request->incoming_invoice_id,
                'incoming_contract_id' => $request->incoming_contract_id,
                'incoming_payment_id' => $request->incoming_payment_id,
                'incoming_company_nit' => $request->incoming_company_nit,
                'template_id' => $request->template_id,
            ]);

            $conversation->update([
                'last_message' => "[Plantilla: $templateName]",
                'last_message_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message_id' => $message->id,
                'wamid' => $message->wamid
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error']['error']['message'] ?? 'Error al enviar plantilla a Meta'
        ], 500);
    }

    /**
     * media_id del encabezado multimedia dentro de la metadata que envía el
     * sistema externo. Acepta tanto el atajo `header_media_id` como los
     * `components` en el formato de Meta.
     */
    private function headerMediaId(?array $metadata): ?string
    {
        if (!empty($metadata['header_media_id'])) {
            return (string) $metadata['header_media_id'];
        }

        foreach ($metadata['components'] ?? [] as $component) {
            foreach ($component['parameters'] ?? [] as $param) {
                $mediaKey = $param['type'] ?? '';
                if (in_array($mediaKey, ['document', 'image', 'video'], true) && !empty($param[$mediaKey]['id'])) {
                    return (string) $param[$mediaKey]['id'];
                }
            }
        }

        return null;
    }

    /**
     * Nombre del archivo declarado en el encabezado multimedia de la plantilla.
     */
    private function headerFilename(?array $metadata): ?string
    {
        if (!empty($metadata['filename'])) {
            return (string) $metadata['filename'];
        }

        foreach ($metadata['components'] ?? [] as $component) {
            foreach ($component['parameters'] ?? [] as $param) {
                $mediaKey = $param['type'] ?? '';
                if (in_array($mediaKey, ['document', 'image', 'video'], true) && !empty($param[$mediaKey]['filename'])) {
                    return (string) $param[$mediaKey]['filename'];
                }
            }
        }

        return null;
    }

    public function registerMessage(Request $request)
    {
        $instance = $this->validateInstance($request);
        if ($instance instanceof \Illuminate\Http\JsonResponse) {
            return $instance;
        }
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $validator = Validator::make($request->all(), [
            'to' => 'required|string',
            'wamid' => 'required|string|unique:whatsapp_messages,wamid',
            'content' => 'required|string',
            'type' => 'nullable|string', // text, image, document, template, etc.
            'status' => 'nullable|string', // sent, delivered, read, failed
            'direction' => 'nullable|string|in:inbound,outbound',
            'name' => 'nullable|string', // contact name
            'media_url' => 'nullable|string',
            'media_id' => 'nullable|string',
            'media_mime_type' => 'nullable|string',
            'filename' => 'nullable|string',
            'metadata' => 'nullable|array',
            'sent_at' => 'nullable', // ISO8601 timestamp
            'incoming_invoice_id' => 'nullable|integer',
            'incoming_contract_id' => 'nullable|integer',
            'incoming_payment_id' => 'nullable|integer',
            'incoming_company_nit' => 'nullable|integer',
            'template_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // El destinatario se normaliza aquí, en la frontera: el sistema externo lo
        // manda como lo tenga guardado ("57300 825 3303", "+57 300...") y así
        // llegaba tal cual al hilo y a WhatsApp. El hilo escrito de otra forma
        // que el que abre el webhook partía la conversación en dos. Un BSUID pasa
        // intacto: quitarle las letras lo convertiría en un teléfono inventado.
        $to = WhatsAppConversation::normalizeRecipient($request->to);

        if ($to === '') {
            return response()->json(['errors' => ['to' => [
                'El destinatario debe ser un número de teléfono o un identificador de WhatsApp (por ejemplo CO.1402615141764490).',
            ]]], 422);
        }

        $wamid = $request->wamid;
        $content = $request->content;
        $type = $request->type ?? 'text';
        $status = $request->status ?? 'sent';
        $direction = $request->direction ?? 'outbound';
        $sentAt = $request->sent_at ? Carbon::parse($request->sent_at) : now();
        $metadata = $request->metadata;
        $mediaUrl = $request->media_url;
        $filename = $request->filename;
        $mediaId = $request->media_id ?: $this->headerMediaId($metadata);
        $mediaMimeType = $request->media_mime_type;

        // Documentos y plantillas con header multimedia: el sistema externo solo
        // conoce el media_id que subió a Meta. Descargamos una copia a nuestro S3
        // para que el archivo quede visible/descargable en el chat. Si la descarga
        // falla igual guardamos el media_id: el chat lo reintenta al abrirlo.
        if (!$mediaUrl && $mediaId && !empty($instance->access_token)) {
            $mediaInfo = $this->metaService->downloadMedia($mediaId, $instance->access_token);
            if ($mediaInfo) {
                $mediaUrl = $mediaInfo['url'];
                $filename = $filename ?: ($metadata['filename'] ?? $mediaInfo['filename']);
                $mediaMimeType = $mediaMimeType ?: $mediaInfo['mime_type'];
            }
        }

        // Un tipo multimedia sin archivo recuperable (ni copia propia ni media_id)
        // se pinta como una tarjeta de adjunto vacía —"Archivo no disponible"— que
        // además esconde el texto: los avisos de pago registrado llegaban con
        // aspecto de archivo roto. Si no hay nada que adjuntar, el mensaje vale
        // como texto y el agente lee la confirmación.
        if (!$mediaUrl && !$mediaId && in_array($type, ['document', 'image', 'audio', 'video', 'sticker'], true)) {
            $type = 'text';
        }

        // Find or create conversation
        $conversation = WhatsAppConversation::resolveFor(
            $instance->id,
            $to,
            [
                'phone_number' => $to,
                'name' => $request->name ?? $to,
                'status' => 'open',
                'last_message_at' => $sentAt
            ]
        );

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => $wamid,
            'type' => $type,
            'content' => $content,
            'media_url' => $mediaUrl,
            'media_id' => $mediaId,
            'media_mime_type' => $mediaMimeType,
            'filename' => $filename,
            'direction' => $direction,
            'status' => $status,
            'metadata' => $metadata,
            'sent_at' => $sentAt,
            'incoming_invoice_id' => $request->incoming_invoice_id,
            'incoming_contract_id' => $request->incoming_contract_id,
            'incoming_payment_id' => $request->incoming_payment_id,
            'incoming_company_nit' => $request->incoming_company_nit,
            'template_id' => $request->template_id,
        ]);

        $conversation->update([
            'last_message' => $content,
            'last_message_at' => $sentAt
        ]);

        return response()->json([
            'success' => true,
            'message_id' => $message->id,
            'wamid' => $message->wamid
        ]);
    }

    public function getConversations(Request $request)
    {
        $instance = $this->validateInstance($request);
        if ($instance instanceof \Illuminate\Http\JsonResponse) {
            return $instance;
        }
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $perPage = $request->query('per_page', 20);
        
        $conversations = WhatsAppConversation::where('instance_id', $instance->id)
            ->orderBy('last_message_at', 'desc')
            ->paginate($perPage);

        $items = $conversations->items();
        // Ensure items are arrays for sanitization
        $data = array_map(function($item) {
            return $item->toArray();
        }, $items);

        return response()->json([
            'success' => true,
            'data' => $this->sanitizeUtf8($data),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total()
            ]
        ]);
    }

    public function getMessages(Request $request, $conversationId)
    {
        $instance = $this->validateInstance($request);
        if ($instance instanceof \Illuminate\Http\JsonResponse) {
            return $instance;
        }
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $conversation = WhatsAppConversation::where('instance_id', $instance->id)
            ->findOrFail($conversationId);

        $perPage = $request->query('per_page', 50);

        $messages = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->orderBy('sent_at', 'desc')
            ->paginate($perPage);

        $items = $messages->items();
        $data = array_map(function($item) {
            return $item->toArray();
        }, $items);

        return response()->json([
            'success' => true,
            'data' => $this->sanitizeUtf8($data),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total()
            ]
        ]);
    }

    public function getWhatsAppMessages(Request $request)
    {
        $instance = $this->validateInstance($request);
        if ($instance instanceof \Illuminate\Http\JsonResponse) {
            return $instance;
        }
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $validator = Validator::make($request->all(), [
            'incoming_company_nit' => 'required',
            'date_from' => 'required|date',
            'date_to' => 'required|date',
            'status' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = WhatsAppMessage::query();
        
        // Filter by company nit
        $query->where('incoming_company_nit', $request->incoming_company_nit);

        // Filter by date range
        $dateFrom = Carbon::parse($request->date_from)->startOfDay();
        $dateTo = Carbon::parse($request->date_to)->endOfDay();
        $query->whereBetween('created_at', [$dateFrom, $dateTo]);

        // Filter by status if provided
        if ($request->has('status') && !empty($request->status)) {
            $statuses = array_map('trim', explode(',', $request->status));
            $query->whereIn('status', $statuses);
        }

        // Pagination
        $perPage = $request->query('per_page', 100);
        $messages = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $items = $messages->items();
        $data = array_map(function($item) {
            return $item->toArray();
        }, $items);

        return response()->json([
            'success' => true,
            'data' => $this->sanitizeUtf8($data),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total()
            ]
        ]);
    }

    /**
     * Recursively sanitize array data to ensure valid UTF-8.
     *
     * @param mixed $input
     * @return mixed
     */
    private function sanitizeUtf8($input)
    {
        if (is_string($input)) {
            return mb_convert_encoding($input, 'UTF-8', 'UTF-8');
        } elseif (is_array($input)) {
            foreach ($input as &$value) {
                $value = $this->sanitizeUtf8($value);
            }
            unset($value);
        }
        return $input;
    }

    /**
     * Envía el aviso como la plantilla de respaldo de la instancia y lo guarda
     * en el hilo.
     *
     * Lo que se guarda como contenido es el cuerpo ya renderizado —lo que el
     * cliente vio de verdad—, no el texto suelto del ERP: si el agente lee en el
     * chat algo distinto de lo que llegó al teléfono, la conversación siguiente
     * arranca torcida. El texto original queda en la metadata.
     */
    private function deliverFallbackTemplate(
        Request $request,
        Instance $instance,
        WhatsAppConversation $conversation,
        string $to,
        string $originalText,
        array $fallback
    ) {
        Log::channel('whatsapp')->info('🔁 Ventana de 24h cerrada: el aviso sale como plantilla de respaldo', [
            'company_id' => $instance->company_id,
            'conversation_id' => $conversation->id,
            'template' => $fallback['name'],
            'language' => $fallback['language'],
        ]);

        $guard = $this->templateGuard->check(
            $instance,
            $fallback['name'],
            $fallback['language'],
            $fallback['components']
        );

        if (!$guard['ok']) {
            Log::channel('whatsapp')->error('❌ La plantilla de respaldo no cuadra con su definición en Meta', [
                'company_id'  => $instance->company_id,
                'template'    => $fallback['name'],
                'guard_error' => $guard['error'],
            ]);

            return response()->json([
                'success' => false,
                'code'    => 'fallback_template_failed',
                'error'   => $guard['error'],
            ], 500);
        }

        $result = $this->metaService->sendTemplate(
            $instance->phone_number_id,
            $to,
            $fallback['name'],
            $fallback['language'],
            $guard['components']
        );

        if (!($result['success'] ?? false)) {
            Log::channel('whatsapp')->error('❌ Falló el envío de la plantilla de respaldo', [
                'company_id' => $instance->company_id,
                'conversation_id' => $conversation->id,
                'template' => $fallback['name'],
                'error' => $result['error'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'code' => 'fallback_template_failed',
                'error' => $result['error']['error']['message'] ?? 'Error al enviar la plantilla de respaldo a Meta',
            ], 500);
        }

        $content = $fallback['preview'] ?? $originalText;

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => $result['data']['messages'][0]['id'],
            // Sin encabezado multimedia no hay nada que adjuntar: la burbuja de
            // plantilla dejaría el aviso detrás de una tarjeta vacía.
            'type' => 'text',
            'content' => $content,
            'direction' => 'outbound',
            'status' => 'sent',
            'sent_at' => now(),
            'incoming_invoice_id' => $request->incoming_invoice_id,
            'incoming_contract_id' => $request->incoming_contract_id,
            'incoming_payment_id' => $request->incoming_payment_id,
            'incoming_company_nit' => $request->incoming_company_nit,
            'template_id' => $request->template_id,
            'metadata' => [
                'window_guard' => 'fallback_template',
                'template' => $fallback['name'],
                'language' => $fallback['language'],
                'components' => $fallback['components'],
                'original_text' => $originalText,
            ],
        ]);

        $conversation->update([
            'last_message' => $content,
            'last_message_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message_id' => $message->id,
            'wamid' => $message->wamid,
            // El ERP no tiene que enterarse para funcionar, pero saber que su
            // aviso viajó como plantilla le permite contabilizar el coste y
            // detectar a los clientes que nunca contestan.
            'sent_as' => 'template',
            'template_name' => $fallback['name'],
        ]);
    }

    /**
     * Por qué el respaldo automático no pudo rescatar este aviso, en una frase
     * que le sirva a quien lea la respuesta de la API.
     */
    private function fallbackHint(?array $fallback): string
    {
        return match ($fallback['status'] ?? null) {
            WhatsAppFallbackTemplateService::STATUS_PENDING =>
                'La plantilla de respaldo de esta línea ya está creada y espera aprobación de Meta; '
                . 'en cuanto se apruebe, estos avisos saldrán solos como plantilla.',
            WhatsAppFallbackTemplateService::STATUS_REJECTED =>
                'Meta rechazó la plantilla de respaldo de esta línea: revísala en Ajustes > WhatsApp.',
            WhatsAppFallbackTemplateService::STATUS_DISABLED =>
                'El respaldo automático está desactivado para esta línea.',
            WhatsAppFallbackTemplateService::STATUS_UNAVAILABLE =>
                'No se pudo comprobar la plantilla de respaldo con Meta.',
            default =>
                'Esta línea todavía no tiene una plantilla de respaldo aprobada.',
        };
    }

    /**
     * ¿Se rechaza el texto libre fuera de la ventana, o sólo se marca?
     *
     * Arrancar rechazando a todas las empresas a la vez convierte meses de
     * pérdidas silenciosas en cientos de errores diarios para el ERP de un día
     * para otro. `enforce_companies` permite encenderlo de una en una, empezando
     * por la que se pueda acompañar, sin tocar el modo global.
     */
    private function windowGuardEnforced(Instance $instance): bool
    {
        $guard = config('whatsapp.window_guard');

        if (in_array((string) $instance->company_id, $guard['enforce_companies'] ?? [], true)) {
            return true;
        }

        return ($guard['mode'] ?? 'shadow') === 'enforce';
    }
}
