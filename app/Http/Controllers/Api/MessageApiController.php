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
use Carbon\Carbon;

class MessageApiController extends Controller
{
    private $metaService;

    public function __construct(MetaWhatsAppService $metaService)
    {
        $this->metaService = $metaService;
    }

    private function validateInstance(Request $request)
    {
        $token = $request->header('X-Instance-Token');

        if (!$token) {
            return null;
        }

        return Instance::where('phone_number_id', $token)
            ->where('active', true)
            ->first();
    }

    public function sendMessage(Request $request)
    {
        $instance = $this->validateInstance($request);
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
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // El número se normaliza aquí, en la frontera: el sistema externo lo
        // manda como lo tenga guardado ("57300 825 3303", "+57 300...") y así
        // llegaba tal cual al hilo y a WhatsApp. El hilo escrito de otra forma
        // que el que abre el webhook partía la conversación en dos.
        $to = WhatsAppConversation::normalizePhone($request->to);

        if ($to === '') {
            return response()->json(['errors' => ['to' => ['El número de destino no contiene dígitos.']]], 422);
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

        if ($windowClosed && $this->windowGuardEnforced($instance)) {
            return response()->json([
                'success' => false,
                'code' => 'window_closed',
                'error' => 'El destinatario no escribe desde hace más de 24 horas. '
                    . 'WhatsApp no permite texto libre fuera de esa ventana: '
                    . 'envía una plantilla aprobada con POST /api/v1/messages/template.',
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
                // saber si el guardarraíl habría acertado.
                'metadata' => $windowClosed ? ['window_guard' => 'shadow_pass'] : null,
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

        // El número se normaliza aquí, en la frontera: el sistema externo lo
        // manda como lo tenga guardado ("57300 825 3303", "+57 300...") y así
        // llegaba tal cual al hilo y a WhatsApp. El hilo escrito de otra forma
        // que el que abre el webhook partía la conversación en dos.
        $to = WhatsAppConversation::normalizePhone($request->to);

        if ($to === '') {
            return response()->json(['errors' => ['to' => ['El número de destino no contiene dígitos.']]], 422);
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

        // El número se normaliza aquí, en la frontera: el sistema externo lo
        // manda como lo tenga guardado ("57300 825 3303", "+57 300...") y así
        // llegaba tal cual al hilo y a WhatsApp. El hilo escrito de otra forma
        // que el que abre el webhook partía la conversación en dos.
        $to = WhatsAppConversation::normalizePhone($request->to);

        if ($to === '') {
            return response()->json(['errors' => ['to' => ['El número de destino no contiene dígitos.']]], 422);
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
