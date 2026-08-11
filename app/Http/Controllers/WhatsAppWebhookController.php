<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Instance;
use App\Models\Contact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppCall;
use App\Models\WhatsAppCallPermission;
use App\Services\MetaWhatsAppService;
use App\Services\AutoResponseService;
use App\Services\BusinessHoursService;

class WhatsAppWebhookController extends Controller
{
    private $metaService;
    private $autoResponseService;
    private $businessHoursService;

    public function __construct(
        MetaWhatsAppService $metaService,
        AutoResponseService $autoResponseService,
        BusinessHoursService $businessHoursService
    ) {
        $this->metaService = $metaService;
        $this->autoResponseService = $autoResponseService;
        $this->businessHoursService = $businessHoursService;
    }

    public function verify(Request $request)
    {
        // Try both underscore and dot notation (standard vs Laravel conversion)
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        // Fallback: Manually parse REQUEST_URI if server config strips query string
        if (!$mode && $request->server('REQUEST_URI')) {
            $queryString = parse_url($request->server('REQUEST_URI'), PHP_URL_QUERY);
            if ($queryString) {
                parse_str($queryString, $queryParams);
                $mode = $queryParams['hub_mode'] ?? $queryParams['hub.mode'] ?? null;
                $token = $queryParams['hub_verify_token'] ?? $queryParams['hub.verify_token'] ?? null;
                $challenge = $queryParams['hub_challenge'] ?? $queryParams['hub.challenge'] ?? null;
            }
        }

        $verifyToken = config('services.meta.webhook_verify_token');
        
        if ($mode === 'subscribe' && $token === $verifyToken) {
            Log::channel('whatsapp')->info('✅ Webhook verificado exitosamente');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::channel('whatsapp')->warning('❌ Intento de verificación fallido', [
            'mode' => $mode,
            'token' => $token,
            'ip' => $request->ip()
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Nº de eventos que fallaron en esta petición. Si queda en >0 se responde 500
     * para que Meta reintente el lote: guardar es idempotente por wamid, así que
     * los mensajes ya procesados no se duplican y los perdidos sí se recuperan.
     */
    private int $failedEvents = 0;

    public function webhook(Request $request)
    {
        $data = $request->all();
        $this->failedEvents = 0;

        Log::channel('whatsapp')->info('📩 Webhook recibido de Meta', [
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);

        foreach ($data['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                // Cada change se aísla: un payload que reviente no debe tumbar
                // los demás mensajes del mismo lote.
                try {
                    if (($change['field'] ?? null) === 'messages') {
                        $this->processChange($change['value'] ?? []);
                    } elseif (($change['field'] ?? null) === 'calls') {
                        $this->processCallChange($change['value'] ?? []);
                    }
                } catch (\Throwable $e) {
                    $this->failedEvents++;
                    Log::channel('whatsapp')->error('❌ Error procesando webhook', [
                        'field' => $change['field'] ?? null,
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }

        if ($this->failedEvents > 0) {
            // 500 => Meta reintenta. Antes se respondía 200 siempre y el mensaje
            // se perdía para siempre en cuanto algo fallara.
            return response()->json([
                'status' => 'retry',
                'failed_events' => $this->failedEvents,
            ], 500);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    private function processChange($value)
    {
        $metadata = $value['metadata'] ?? [];
        $phoneNumberId = $metadata['phone_number_id'] ?? null;

        Log::channel('whatsapp')->info('🔍 Identificando instancia', [
            'phone_number_id' => $phoneNumberId
        ]);

        $candidates = Instance::where('phone_number_id', $phoneNumberId)
            ->where('active', true)
            ->orderBy('id')
            ->get();

        $instance = $candidates->first();

        if (!$instance) {
            // Sin instancia activa el mensaje se descarta: queda el remitente en
            // el log para poder rastrear el reporte de "no me llegan mensajes".
            Log::channel('whatsapp')->warning('⚠️ No se encontró instancia activa: mensajes descartados', [
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => $metadata['display_phone_number'] ?? null,
                'from' => array_column($value['messages'] ?? [], 'from'),
                'inactive_instances' => Instance::where('phone_number_id', $phoneNumberId)
                    ->pluck('company_id', 'id'),
            ]);
            return;
        }

        // El índice único de instances es (company_id, phone_number_id): dos
        // empresas pueden reclamar el mismo número y los mensajes se irían todos
        // a la primera, que es justo el síntoma de "a esta empresa no le llegan".
        if ($candidates->count() > 1) {
            Log::channel('whatsapp')->error('🚨 phone_number_id duplicado en varias empresas activas', [
                'phone_number_id' => $phoneNumberId,
                'instances' => $candidates->pluck('company_id', 'id'),
                'usando_instance_id' => $instance->id,
            ]);
        }

        Log::channel('whatsapp')->info('✅ Instancia identificada', [
            'instance_id' => $instance->id,
            'company_id' => $instance->company_id
        ]);

        if (isset($value['messages'])) {
            foreach ($value['messages'] as $message) {
                // Aislado por mensaje: si uno falla, los demás del lote se guardan
                // igual y el 500 final hace que Meta reintente el que falló.
                try {
                    $this->processInboundMessage($message, $instance, $value);
                } catch (\Throwable $e) {
                    $this->failedEvents++;
                    Log::channel('whatsapp')->error('❌ Error guardando mensaje entrante', [
                        'instance_id' => $instance->id,
                        'from' => $message['from'] ?? null,
                        'wamid' => $message['id'] ?? null,
                        'type' => $message['type'] ?? null,
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }

        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $status) {
                // Un acuse de recibo que falle no puede impedir que se procesen
                // los mensajes nuevos del mismo lote.
                try {
                    $this->updateMessageStatus($status, $instance);
                } catch (\Throwable $e) {
                    Log::channel('whatsapp')->error('❌ Error actualizando estado de mensaje', [
                        'wamid' => $status['id'] ?? null,
                        'status' => $status['status'] ?? null,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Enruta los eventos del campo "calls" del webhook hacia processCallEvent,
     * identificando primero la instancia por phone_number_id (igual que los mensajes).
     */
    private function processCallChange($value)
    {
        $metadata = $value['metadata'] ?? [];
        $phoneNumberId = $metadata['phone_number_id'] ?? null;

        $instance = Instance::where('phone_number_id', $phoneNumberId)
            ->where('active', true)
            ->first();

        if (!$instance) {
            Log::channel('whatsapp')->warning('⚠️ Llamada recibida sin instancia activa', [
                'phone_number_id' => $phoneNumberId,
            ]);
            return;
        }

        foreach ($value['calls'] ?? [] as $call) {
            $this->processCallEvent($call, $instance);
        }
    }

    /**
     * Procesa un evento individual de llamada de la Calling API de WhatsApp.
     *
     * Fase 1 (señalización): registra y actualiza el ciclo de vida de la llamada
     * en whatsapp_calls. La conexión real del audio (responder con SDP answer vía
     * acceptCall) se hará desde el servidor de media en una fase posterior.
     */
    private function processCallEvent($call, Instance $instance)
    {
        $callId = $call['id'] ?? null;
        if (!$callId) {
            Log::channel('whatsapp')->warning('⚠️ Evento de llamada sin id', ['call' => $call]);
            return;
        }

        // Meta usa "event" (connect/terminate) y "status" (RINGING/ACCEPTED/...)
        // según la versión. Normalizamos ambos a minúsculas para decidir.
        $event = strtolower($call['event'] ?? '');
        $status = strtolower($call['status'] ?? '');
        $rawDirection = strtoupper($call['direction'] ?? '');
        $direction = str_contains($rawDirection, 'BUSINESS') ? 'outbound' : 'inbound';

        $from = $call['from'] ?? null;
        $to = $call['to'] ?? null;
        $sdp = $call['session']['sdp'] ?? null;

        // Para entrantes el contraparte es "from"; para salientes es "to"
        $counterpart = $direction === 'inbound' ? $from : $to;

        $conversation = null;
        if ($counterpart) {
            $conversation = WhatsAppConversation::resolveFor(
                $instance->id,
                $counterpart,
                [
                    'phone_number' => $counterpart,
                    'name' => $counterpart,
                    'status' => 'open',
                    'last_message_at' => now(),
                ]
            );
            $this->ensureContactRegistered($conversation, $instance, $counterpart, $conversation->name ?? $counterpart);
        }

        $record = WhatsAppCall::firstOrNew(['wacid' => $callId]);
        $isNew = !$record->exists;

        if (!$record->exists) {
            $record->fill([
                'instance_id' => $instance->id,
                'conversation_id' => $conversation?->id,
                'direction' => $direction,
                'status' => 'ringing',
                'from' => $from,
                'to' => $to,
                'started_at' => now(),
            ]);
        }

        // Guardar la oferta SDP entrante para que el servidor de media la use al aceptar
        if ($sdp && ($call['session']['sdp_type'] ?? null) === 'offer') {
            $record->offer_sdp = $sdp;
        }

        // Resolver el estado nuevo a partir de event/status
        $isTerminate = $event === 'terminate' || in_array($status, ['completed', 'failed', 'rejected', 'missed', 'canceled']);

        if ($isTerminate) {
            $newStatus = match (true) {
                $status === 'rejected' => 'rejected',
                $status === 'failed' => 'failed',
                $status === 'missed' => 'missed',
                $status === 'canceled' => 'canceled',
                // terminate sin haber conectado audio => perdida (entrante) / cancelada (saliente)
                !$record->connected_at => $direction === 'inbound' ? 'missed' : 'canceled',
                default => 'completed',
            };
            $record->ended_at = now();
            $record->duration_seconds = $record->connected_at
                ? $record->connected_at->diffInSeconds($record->ended_at)
                : 0;
            $record->status = $newStatus;
        } elseif (in_array($status, ['accepted', 'in_progress', 'connected'])) {
            $record->status = 'in_progress';
            $record->connected_at = $record->connected_at ?? now();
        } elseif ($status === 'connecting') {
            $record->status = 'connecting';
        }

        // Conservar el payload crudo para depuración / fases siguientes
        $meta = $record->metadata ?? [];
        $meta['last_event'] = $call;
        $record->metadata = $meta;

        $record->save();

        // Notificar al frontend en tiempo real (Reverb). action:
        // - incoming: nueva llamada entrante timbrando (mostrar UI de contestar)
        // - ended: la llamada terminó (cualquier estado final)
        // - status: actualización de estado intermedia
        $action = match (true) {
            $isNew && $direction === 'inbound' => 'incoming',
            in_array($record->status, ['completed', 'missed', 'rejected', 'failed', 'canceled']) => 'ended',
            default => 'status',
        };

        try {
            broadcast(new \App\Events\WhatsAppCallEvent($record, $action));
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('No se pudo emitir el evento de llamada', ['error' => $e->getMessage()]);
        }

        Log::channel('whatsapp')->info('📞 Evento de llamada procesado', [
            'wacid' => $callId,
            'direction' => $direction,
            'event' => $event ?: $status,
            'status' => $record->status,
            'broadcast' => $action,
        ]);
    }

    /**
     * Procesa la respuesta del usuario a una solicitud de permiso de llamada.
     * Actualiza (o crea) el registro de permiso para ese contacto en la instancia.
     */
    private function processCallPermissionReply($message, Instance $instance, WhatsAppConversation $conversation)
    {
        $from = $message['from'] ?? $conversation->wa_id;
        $reply = $message['interactive']['call_permission_reply'] ?? [];

        // Meta indica la decisión en "response" (accept/reject) y, si acepta, la
        // ventana de validez en "expiration_timestamp".
        $response = strtolower($reply['response'] ?? '');
        $granted = in_array($response, ['accept', 'accepted', 'approve', 'approved', 'yes']);

        $expiresAt = null;
        if (!empty($reply['expiration_timestamp'])) {
            $expiresAt = \Carbon\Carbon::createFromTimestamp($reply['expiration_timestamp']);
        }

        WhatsAppCallPermission::updateOrCreate(
            ['instance_id' => $instance->id, 'wa_id' => $from],
            [
                'conversation_id' => $conversation->id,
                'status' => $granted ? 'granted' : 'rejected',
                'expires_at' => $expiresAt,
                'responded_at' => now(),
                'metadata' => ['last_reply' => $reply],
            ]
        );

        Log::channel('whatsapp')->info('🔐 Permiso de llamada actualizado', [
            'instance_id' => $instance->id,
            'wa_id' => $from,
            'status' => $granted ? 'granted' : 'rejected',
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);
    }

    private function processInboundMessage($message, Instance $instance, $metadata)
    {
        $from = $message['from'];
        $wamid = $message['id'];
        $timestamp = $message['timestamp'];

        $contactName = 'Desconocido';
        if (isset($metadata['contacts']) && count($metadata['contacts']) > 0) {
            $contactName = $metadata['contacts'][0]['profile']['name'] ?? $from;
        }

        // resolveFor reconoce el hilo aunque se haya creado con el número escrito
        // de otra forma (con espacios, sin indicativo o con el indicativo
        // repetido): antes se abría un hilo nuevo y la respuesta del cliente
        // quedaba invisible en el chat que el agente estaba mirando.
        $conversation = WhatsAppConversation::resolveFor(
            $instance->id,
            $from,
            [
                'phone_number' => $from,
                'name' => $contactName,
                'status' => 'open',
                'last_message_at' => now()
            ]
        );

        // Registrar automáticamente el contacto entrante si aún no está registrado.
        // Es accesorio: si falla (contacto corrupto, choque de datos) el mensaje
        // se guarda igual — antes una excepción aquí lo hacía desaparecer.
        try {
            $this->ensureContactRegistered($conversation, $instance, $from, $contactName);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ No se pudo registrar el contacto del mensaje entrante', [
                'conversation_id' => $conversation->id,
                'from' => $from,
                'error' => $e->getMessage(),
            ]);
        }

        // Respuesta a una solicitud de permiso de llamada: se procesa aparte y no
        // se guarda como un mensaje normal del chat.
        if (($message['type'] ?? null) === 'interactive'
            && ($message['interactive']['type'] ?? null) === 'call_permission_reply') {
            $this->processCallPermissionReply($message, $instance, $conversation);
            return;
        }

        // Reacción (emoji) a un mensaje existente: se adjunta al mensaje original
        // en vez de crear una burbuja nueva. No genera "tipo no soportado".
        if (($message['type'] ?? null) === 'reaction') {
            $this->processReaction($message, $conversation);
            return;
        }

        $existingMessage = WhatsAppMessage::where('wamid', $wamid)->first();
        if ($existingMessage) {
            Log::channel('whatsapp')->info('ℹ️ Mensaje duplicado, ignorando', ['wamid' => $wamid]);
            return;
        }

        $messageData = [
            'conversation_id' => $conversation->id,
            'wamid' => $wamid,
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => \Carbon\Carbon::createFromTimestamp($timestamp)
        ];

        // Los avisos del sistema (cambio de número, cambio de identidad) no son
        // mensajes escritos por el cliente: no cuentan como no leídos ni deben
        // disparar respuestas automáticas.
        $isSystemNotice = false;
        $skipAutoResponse = false;

        switch ($message['type']) {
            case 'text':
                $messageData['type'] = 'text';
                $messageData['content'] = $message['text']['body'];
                break;

            case 'image':
                $messageData['type'] = 'image';
                $messageData['media_id'] = $message['image']['id'];
                $messageData['media_mime_type'] = $message['image']['mime_type'];
                $messageData['content'] = $message['image']['caption'] ?? '';

                $mediaInfo = $this->metaService->downloadMedia($message['image']['id'], $instance->access_token);
                if ($mediaInfo) {
                    $messageData['media_url'] = $mediaInfo['url'];
                    $messageData['filename'] = $mediaInfo['filename'];
                }
                break;

            case 'document':
                $messageData['type'] = 'document';
                $messageData['media_id'] = $message['document']['id'];
                $messageData['media_mime_type'] = $message['document']['mime_type'];
                $messageData['filename'] = $message['document']['filename'] ?? 'document';

                $mediaInfo = $this->metaService->downloadMedia($message['document']['id'], $instance->access_token);
                if ($mediaInfo) {
                    $messageData['media_url'] = $mediaInfo['url'];
                }
                break;

            case 'audio':
                $messageData['type'] = 'audio';
                $messageData['media_id'] = $message['audio']['id'];
                $messageData['media_mime_type'] = $message['audio']['mime_type'];

                $mediaInfo = $this->metaService->downloadMedia($message['audio']['id'], $instance->access_token);
                if ($mediaInfo) {
                    $messageData['media_url'] = $mediaInfo['url'];
                    $messageData['filename'] = $mediaInfo['filename'];
                }
                break;

            case 'video':
                $messageData['type'] = 'video';
                $messageData['media_id'] = $message['video']['id'];
                $messageData['media_mime_type'] = $message['video']['mime_type'];
                $messageData['content'] = $message['video']['caption'] ?? '';

                $mediaInfo = $this->metaService->downloadMedia($message['video']['id'], $instance->access_token);
                if ($mediaInfo) {
                    $messageData['media_url'] = $mediaInfo['url'];
                    $messageData['filename'] = $mediaInfo['filename'];
                }
                break;

            case 'sticker':
                $messageData['type'] = 'sticker';
                $messageData['media_id'] = $message['sticker']['id'];
                $messageData['media_mime_type'] = $message['sticker']['mime_type'] ?? 'image/webp';

                $mediaInfo = $this->metaService->downloadMedia($message['sticker']['id'], $instance->access_token);
                if ($mediaInfo) {
                    $messageData['media_url'] = $mediaInfo['url'];
                    $messageData['filename'] = $mediaInfo['filename'];
                }
                break;

            case 'contacts':
                // Tarjetas de contacto compartidas. Se guarda el payload completo en
                // metadata para que el chat pueda mostrar nombre(s) y teléfono(s).
                $contacts = $message['contacts'] ?? [];
                $names = array_values(array_filter(array_map(
                    fn ($c) => $c['name']['formatted_name'] ?? ($c['phones'][0]['phone'] ?? null),
                    $contacts
                )));
                $messageData['type'] = 'contacts';
                $messageData['content'] = $names ? implode(', ', $names) : 'Contacto compartido';
                $messageData['metadata'] = ['contacts' => $contacts];
                break;

            case 'location':
                $location = $message['location'] ?? [];
                $messageData['type'] = 'location';
                $messageData['content'] = trim(implode(' — ', array_filter([
                    $location['name'] ?? null,
                    $location['address'] ?? null,
                ]))) ?: 'Ubicación compartida';
                $messageData['metadata'] = ['location' => $location];
                break;

            case 'interactive':
                // Respuesta a botones o listas interactivas: se guarda como texto
                // con el título de la opción elegida.
                $interactive = $message['interactive'] ?? [];
                $reply = $interactive['button_reply'] ?? $interactive['list_reply'] ?? [];
                $messageData['type'] = 'text';
                $messageData['content'] = $reply['title'] ?? 'Respuesta interactiva';
                $messageData['metadata'] = ['interactive' => $interactive];
                break;

            case 'button':
                // Respuesta a un botón rápido de plantilla.
                $messageData['type'] = 'text';
                $messageData['content'] = $message['button']['text'] ?? 'Botón';
                $messageData['metadata'] = ['button' => $message['button'] ?? []];
                break;

            case 'system':
                // Avisos de la propia plataforma: el cliente cambió de número o
                // reinstaló WhatsApp (cambio de identidad). Llegan en el hilo del
                // número antiguo y traen el nuevo wa_id.
                $system = $message['system'] ?? [];
                $isSystemNotice = true;
                $skipAutoResponse = true;
                $messageData['type'] = 'system';
                $messageData['content'] = $this->describeSystemMessage($system, $from);
                $messageData['metadata'] = ['system' => $system];
                break;

            case 'unsupported':
                // Meta recibió del cliente un tipo que la Cloud API no entrega
                // (p. ej. encuestas o mensajes efímeros). Deja el detalle del error.
                $error = $message['errors'][0] ?? [];
                $detail = $error['title'] ?? ($error['message'] ?? null);
                $skipAutoResponse = true;
                $messageData['type'] = 'system';
                $messageData['content'] = 'El cliente envió un mensaje que WhatsApp no permite recibir'
                    . ($detail ? " ({$detail})" : '');
                $messageData['metadata'] = ['errors' => $message['errors'] ?? []];
                break;

            case 'request_welcome':
                // El cliente abrió el chat desde un anuncio click-to-WhatsApp y aún
                // no ha escrito nada.
                $isSystemNotice = true;
                $skipAutoResponse = true;
                $messageData['type'] = 'system';
                $messageData['content'] = 'El cliente abrió la conversación (aún no ha escrito)';
                break;

            case 'order':
                // Pedido creado desde el catálogo.
                $order = $message['order'] ?? [];
                $items = $order['product_items'] ?? [];
                $messageData['type'] = 'text';
                $messageData['content'] = trim('🛒 Pedido con ' . count($items) . ' producto(s)'
                    . (!empty($order['text']) ? ": {$order['text']}" : ''));
                $messageData['metadata'] = ['order' => $order];
                break;

            default:
                // Tipo desconocido: se guarda el payload íntegro para poder darle
                // soporte después sin perder el contenido original.
                $skipAutoResponse = true;
                $messageData['type'] = 'system';
                $messageData['content'] = "Mensaje no compatible ({$message['type']})";
                $messageData['metadata'] = ['unhandled' => $message];

                Log::channel('whatsapp')->warning('⚠️ Tipo de mensaje sin soporte', [
                    'instance_id' => $instance->id,
                    'type' => $message['type'],
                    'wamid' => $wamid,
                ]);
        }

        // Si el cliente respondió a un mensaje, Meta envía el wamid citado en context.
        if (isset($message['context']['id'])) {
            $messageData['reply_to_wamid'] = $message['context']['id'];
        }

        $savedMessage = WhatsAppMessage::create($messageData);

        $conversationUpdate = [
            'last_message' => ($messageData['type'] === 'system' ? 'ℹ️ ' : '') . ($messageData['content'] ?? 'Media'),
            'last_message_at' => now(),
        ];

        // Si el cliente vuelve a escribir, una conversación cerrada se reabre
        // automáticamente para que no quede oculta en "Cerradas" y vuelva a la
        // bandeja normal de chats abiertos.
        if ($conversation->status === 'closed') {
            $conversationUpdate['status'] = 'open';
        }

        $conversation->update($conversationUpdate);

        if (!$isSystemNotice) {
            $conversation->incrementUnread();
        }

        // Cambio de número: el hilo debe seguir apuntando al número nuevo, si no
        // los mensajes que enviemos después irían al número que ya no existe.
        if (($message['type'] ?? null) === 'system') {
            $this->applyCustomerNumberChange($instance, $conversation, $message['system'] ?? []);
        }

        // Tiempo real: empuja el mensaje entrante a los agentes conectados. Si
        // Reverb no responde el mensaje ya está guardado, así que solo se avisa
        // (el poll del chat lo recogerá igual).
        try {
            broadcast(new \App\Events\WhatsAppMessageEvent($savedMessage->load('sender'), $instance->id, 'new'));
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ No se pudo emitir el mensaje en tiempo real', [
                'message_id' => $savedMessage->id,
                'error' => $e->getMessage(),
            ]);
        }

        // El "leído" (doble check azul) para el cliente solo se envía cuando un
        // agente realmente abre la conversación (ver ChatController::messages()
        // y ::startConversation()), no apenas llega el mensaje al webhook.

        // Las respuestas automáticas son un efecto secundario: si fallan, el
        // mensaje del cliente ya quedó guardado y no se debe reintentar el lote.
        if (!$skipAutoResponse) {
            try {
                $handledOutOfHours = $this->businessHoursService->handleInbound($instance, $conversation);

                if (!$handledOutOfHours) {
                    $this->autoResponseService->handleInbound($instance, $conversation, $messageData['content'] ?? '', $wamid);
                }
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->error('❌ Falló la respuesta automática', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::channel('whatsapp')->info('✅ Mensaje procesado', [
            'instance_id' => $instance->id,
            'message_id' => $savedMessage->id
        ]);
    }

    /**
     * Adjunta una reacción (emoji) al mensaje al que responde. WhatsApp manda
     * `reaction.message_id` (wamid del mensaje original) y `reaction.emoji`
     * (cadena vacía cuando el usuario quita la reacción).
     */
    private function processReaction($message, WhatsAppConversation $conversation): void
    {
        $reaction = $message['reaction'] ?? [];
        $targetWamid = $reaction['message_id'] ?? null;
        $emoji = $reaction['emoji'] ?? null;

        if (!$targetWamid) {
            return;
        }

        $target = WhatsAppMessage::where('wamid', $targetWamid)
            ->whereHas('conversation', fn($q) => $q->where('id', $conversation->id))
            ->first();

        if (!$target) {
            Log::channel('whatsapp')->info('ℹ️ Reacción a un mensaje no encontrado', ['target' => $targetWamid]);
            return;
        }

        $meta = $target->metadata ?? [];
        if ($emoji === null || $emoji === '') {
            unset($meta['reaction']);
        } else {
            $meta['reaction'] = $emoji;
        }
        $target->metadata = $meta;
        $target->save();

        Log::channel('whatsapp')->info('🙂 Reacción procesada', [
            'target_wamid' => $targetWamid,
            'emoji' => $emoji ?: '(removida)',
        ]);
    }

    /**
     * Texto en español para un aviso `system` de WhatsApp. El `body` que manda
     * Meta viene en inglés y con marcas de dirección de texto (LRM/RLM), así que
     * se arma la frase aquí y el original queda guardado en metadata.
     */
    private function describeSystemMessage(array $system, string $from): string
    {
        $type = $system['type'] ?? '';
        // v12.0+ manda el número nuevo en `wa_id`; las versiones viejas en `new_wa_id`.
        $newWaId = $system['wa_id'] ?? $system['new_wa_id'] ?? null;

        return match ($type) {
            'user_changed_number', 'customer_changed_number' => $newWaId && $newWaId !== $from
                ? "El cliente cambió su número de WhatsApp: {$from} → {$newWaId}"
                : 'El cliente cambió su número de WhatsApp',
            'customer_identity_changed' => 'El cliente cambió su identidad de WhatsApp (cambió de teléfono o reinstaló la app). Verifica con quién hablas antes de compartir información sensible.',
            default => $this->cleanSystemBody($system['body'] ?? '') ?: 'Aviso del sistema de WhatsApp',
        };
    }

    /**
     * Quita las marcas invisibles de dirección de texto que Meta incluye en el
     * `body` de los avisos del sistema.
     */
    private function cleanSystemBody(string $body): string
    {
        return trim(preg_replace('/[\x{200E}\x{200F}]/u', '', $body) ?? '');
    }

    /**
     * Mueve la conversación al número nuevo cuando el cliente cambia de línea.
     * Sin esto el hilo seguiría apuntando al número viejo y los mensajes salientes
     * no llegarían.
     */
    private function applyCustomerNumberChange(Instance $instance, WhatsAppConversation $conversation, array $system): void
    {
        $type = $system['type'] ?? '';
        if (!in_array($type, ['user_changed_number', 'customer_changed_number'], true)) {
            return;
        }

        // Se compara y se guarda en forma canónica: si el hilo se creó con el
        // número escrito con espacios, un `===` en crudo no reconocería que el
        // número nuevo es el mismo y volvería a partir la conversación.
        $newWaId = WhatsAppConversation::normalizePhone(
            $system['wa_id'] ?? $system['new_wa_id'] ?? null
        );
        $oldWaId = $conversation->wa_id;

        if ($newWaId === '' || $newWaId === WhatsAppConversation::normalizePhone($oldWaId)) {
            return;
        }

        // Si el número nuevo ya tiene su propio hilo, unir historiales es una
        // decisión de negocio: se deja el aviso y ambos hilos intactos.
        $existing = WhatsAppConversation::where('instance_id', $instance->id)
            ->whereRaw("REGEXP_REPLACE(wa_id, '[^0-9]', '') = ?", [$newWaId])
            ->where('id', '!=', $conversation->id)
            ->first();

        if ($existing) {
            Log::channel('whatsapp')->warning('⚠️ Cambio de número con conversación ya existente, no se migra', [
                'instance_id' => $instance->id,
                'old_wa_id' => $oldWaId,
                'new_wa_id' => $newWaId,
                'existing_conversation_id' => $existing->id,
            ]);
            return;
        }

        $metadata = $conversation->metadata ?? [];
        $metadata['previous_wa_ids'] = array_values(array_unique(array_merge(
            $metadata['previous_wa_ids'] ?? [],
            [$oldWaId]
        )));

        $conversation->update([
            'wa_id' => $newWaId,
            'phone_number' => $newWaId,
            'metadata' => $metadata,
        ]);

        // El contacto conserva el número viejo como secundario para que el
        // historial siga siendo rastreable.
        if ($conversation->contact_id && ($contact = Contact::find($conversation->contact_id))) {
            if ($contact->phone_number === $oldWaId) {
                $contact->phone_number = $newWaId;
                $contact->save();
                $contact->addNumber($oldWaId);
            } else {
                $contact->addNumber($newWaId);
            }
        }

        Log::channel('whatsapp')->info('📞 Número del cliente migrado', [
            'conversation_id' => $conversation->id,
            'old_wa_id' => $oldWaId,
            'new_wa_id' => $newWaId,
        ]);
    }

    /**
     * Registra automáticamente el contacto de una conversación entrante.
     *
     * Si ya existe un contacto con ese número (principal o secundario) dentro de
     * la empresa, vincula la conversación a ese contacto. Si no existe, lo crea
     * usando el nombre del perfil de WhatsApp. Así ningún contacto que escriba
     * queda "sin registrar".
     */
    private function ensureContactRegistered(WhatsAppConversation $conversation, Instance $instance, string $phone, string $contactName): void
    {
        // Si la conversación ya está vinculada a un contacto, no hay nada que hacer
        if ($conversation->contact_id) {
            return;
        }

        // Buscar un contacto existente por número principal o secundario
        $contact = Contact::where('company_id', $instance->company_id)
            ->where(function ($q) use ($phone) {
                $q->where('phone_number', $phone)
                  ->orWhere('phone_numbers', 'like', '%"' . $phone . '"%');
            })
            ->first();

        if (!$contact) {
            $contact = Contact::create([
                'company_id' => $instance->company_id,
                'phone_number' => $phone,
                'name' => $contactName,
            ]);

            Log::channel('whatsapp')->info('🆕 Contacto registrado automáticamente', [
                'contact_id' => $contact->id,
                'company_id' => $instance->company_id,
                'phone' => $phone,
            ]);
        } elseif ((empty($contact->name) || $contact->name === 'Desconocido')
            && $contactName && $contactName !== 'Desconocido') {
            // El contacto existía sin nombre: lo completamos con el del perfil
            $contact->update(['name' => $contactName]);
        }

        $conversation->update([
            'contact_id' => $contact->id,
            'name' => $contact->name ?: $conversation->name,
        ]);
    }

    private function updateMessageStatus($status, Instance $instance)
    {
        $wamid = $status['id'];
        $newStatus = $status['status'];

        $message = WhatsAppMessage::where('wamid', $wamid)->first();

        if (!$message) {
            return;
        }

        $updateData = ['status' => $newStatus];

        if ($newStatus === 'delivered') {
            $updateData['delivered_at'] = now();
        } elseif ($newStatus === 'read') {
            $updateData['read_at'] = now();
        } elseif ($newStatus === 'failed') {
            $errors = $status['errors'] ?? [];
            $primaryError = $errors[0] ?? [];
            $errorCode = $primaryError['code'] ?? null;
            $errorTitle = $primaryError['title'] ?? null;
            $errorMessage = $primaryError['message'] ?? 'Error desconocido';
            $errorDetails = $primaryError['error_data']['details'] ?? null;

            $updateData['failed_at'] = now();
            $updateData['error_message'] = $errorMessage;
            $updateData['error_code'] = $errorCode;
            $updateData['error_details'] = $errorDetails;

            Log::channel('whatsapp')->warning('⚠️ Mensaje fallido', [
                'wamid' => $wamid,
                'recipient_id' => $status['recipient_id'] ?? null,
                'error_code' => $errorCode,
                'error_title' => $errorTitle,
                'error_message' => $errorMessage,
                'error_details' => $errorDetails,
                'all_errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                'full_status' => json_encode($status, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $message->update($updateData);

        // Tiempo real: refleja el check (enviado/entregado/leído/fallido) en la UI.
        broadcast(new \App\Events\WhatsAppMessageEvent($message, $instance->id, 'status'));

        Log::channel('whatsapp')->info('✅ Estado actualizado', [
            'wamid' => $wamid,
            'status' => $newStatus
        ]);
    }
}
