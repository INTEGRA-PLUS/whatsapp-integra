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

    public function webhook(Request $request)
    {
        $data = $request->all();

        Log::channel('whatsapp')->info('📩 Webhook recibido de Meta', [
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);

        try {
            if (isset($data['entry'])) {
                foreach ($data['entry'] as $entry) {
                    foreach ($entry['changes'] as $change) {
                        if ($change['field'] === 'messages') {
                            $this->processChange($change['value']);
                        } elseif ($change['field'] === 'calls') {
                            $this->processCallChange($change['value']);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('❌ Error procesando webhook', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    private function processChange($value)
    {
        $metadata = $value['metadata'];
        $phoneNumberId = $metadata['phone_number_id'];

        Log::channel('whatsapp')->info('🔍 Identificando instancia', [
            'phone_number_id' => $phoneNumberId
        ]);

        $instance = Instance::where('phone_number_id', $phoneNumberId)
            ->where('active', true)
            ->first();

        if (!$instance) {
            Log::channel('whatsapp')->warning('⚠️ No se encontró instancia activa', [
                'phone_number_id' => $phoneNumberId
            ]);
            return;
        }

        Log::channel('whatsapp')->info('✅ Instancia identificada', [
            'instance_id' => $instance->id,
            'company_id' => $instance->company_id
        ]);

        if (isset($value['messages'])) {
            foreach ($value['messages'] as $message) {
                $this->processInboundMessage($message, $instance, $value);
            }
        }

        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $status) {
                $this->updateMessageStatus($status, $instance);
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
            $conversation = WhatsAppConversation::firstOrCreate(
                ['instance_id' => $instance->id, 'wa_id' => $counterpart],
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

        $conversation = WhatsAppConversation::firstOrCreate(
            [
                'instance_id' => $instance->id,
                'wa_id' => $from
            ],
            [
                'phone_number' => $from,
                'name' => $contactName,
                'status' => 'open',
                'last_message_at' => now()
            ]
        );

        // Registrar automáticamente el contacto entrante si aún no está registrado
        $this->ensureContactRegistered($conversation, $instance, $from, $contactName);

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

            default:
                $messageData['type'] = 'text';
                $messageData['content'] = "Tipo de mensaje no soportado: {$message['type']}";
        }

        // Si el cliente respondió a un mensaje, Meta envía el wamid citado en context.
        if (isset($message['context']['id'])) {
            $messageData['reply_to_wamid'] = $message['context']['id'];
        }

        $savedMessage = WhatsAppMessage::create($messageData);

        $conversationUpdate = [
            'last_message' => $messageData['content'] ?? 'Media',
            'last_message_at' => now(),
        ];

        // Si el cliente vuelve a escribir, una conversación cerrada se reabre
        // automáticamente para que no quede oculta en "Cerradas" y vuelva a la
        // bandeja normal de chats abiertos.
        if ($conversation->status === 'closed') {
            $conversationUpdate['status'] = 'open';
        }

        $conversation->update($conversationUpdate);
        $conversation->incrementUnread();

        $this->metaService->markAsRead($instance->phone_number_id, $wamid);

        $handledOutOfHours = $this->businessHoursService->handleInbound($instance, $conversation);

        if (!$handledOutOfHours) {
            $this->autoResponseService->handleInbound($instance, $conversation, $messageData['content'] ?? '', $wamid);
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

            $updateData['error_message'] = $errorMessage;

            Log::channel('whatsapp')->warning('⚠️ Mensaje fallido', [
                'wamid' => $wamid,
                'recipient_id' => $status['recipient_id'] ?? null,
                'error_code' => $errorCode,
                'error_title' => $errorTitle,
                'error_message' => $errorMessage,
                'all_errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                'full_status' => json_encode($status, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $message->update($updateData);

        Log::channel('whatsapp')->info('✅ Estado actualizado', [
            'wamid' => $wamid,
            'status' => $newStatus
        ]);
    }
}
