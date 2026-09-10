<?php

namespace App\Services;

use App\Events\CoexistenceSyncEvent;
use App\Models\CoexistenceSync;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\MensajeNoEntregado;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Vuelca a la base los tres webhooks que sólo existen en coexistencia.
 *
 * Los mensajes normales llegan en `value.messages[]` y los procesa
 * `WhatsAppWebhookController`. Los de coexistencia traen otra forma y por eso
 * necesitan camino propio:
 *
 *  - `history`             → `value.history[].threads[].messages[]`, hasta seis
 *                            meses de conversaciones en lotes.
 *  - `smb_app_state_sync`  → `value.state_sync[]`, la agenda de contactos del
 *                            celular, y cada cambio posterior en ella.
 *  - `smb_message_echoes`  → `value.message_echoes[]`, lo que el negocio
 *                            responde desde su celular después de conectarse.
 *
 * Todo entra por `wamid`, que es único en la tabla: Meta reenvía el lote entero
 * si el callback responde 500, así que reprocesar tiene que ser inofensivo.
 */
class CoexistenceIngestService
{
    /**
     * Estados de entrega de Meta traducidos al enum de la tabla.
     *
     * `PLAYED` (una nota de voz escuchada) no tiene equivalente propio y se
     * guarda como leída, que es lo que significa para quien mira el chat.
     */
    private const ESTADOS = [
        'READ' => 'read',
        'PLAYED' => 'read',
        'DELIVERED' => 'delivered',
        'SENT' => 'sent',
        'PENDING' => 'pending',
        'ERROR' => 'failed',
    ];

    // ---------------------------------------------------------------- history

    /**
     * Importa un lote del historial.
     *
     * Un solo webhook puede describir miles de mensajes, así que esto se llama
     * siempre desde la cola, nunca dentro de la petición de Meta.
     */
    public function importarHistorial(Instance $instance, array $value): void
    {
        $sync = $this->sync($instance);

        // Los adjuntos de los mensajes multimedia no vienen en el hilo: llegan
        // en un webhook `history` aparte que sí usa la forma normal
        // (`value.messages[]`) y que completa un mensaje ya guardado.
        if (! empty($value['messages'])) {
            $this->completarMultimedia($value['messages']);

            return;
        }

        $telefonoNegocio = WhatsAppConversation::normalizePhone(
            $value['metadata']['display_phone_number'] ?? $instance->display_phone_number
        );

        foreach ($value['history'] ?? [] as $lote) {
            // El cliente eligió no compartir sus chats. No es un fallo nuestro
            // ni de Meta: es una decisión suya, y la pantalla debe decirlo así.
            if (! empty($lote['errors'])) {
                $error = $lote['errors'][0];
                $sync->update([
                    'status' => (string) ($error['code'] ?? '') === CoexistenceSync::ERROR_SIN_HISTORIAL
                        ? CoexistenceSync::RECHAZADA
                        : CoexistenceSync::FALLIDA,
                    'error_code' => (string) ($error['code'] ?? ''),
                    'error_message' => $error['title'] ?? $error['message'] ?? null,
                    'completed_at' => now(),
                ]);

                Log::channel('whatsapp')->info('📭 Historial no compartido por el negocio', [
                    'instance_id' => $instance->id,
                    'code' => $error['code'] ?? null,
                ]);

                $this->emitir($sync);

                continue;
            }

            $meta = $lote['metadata'] ?? [];

            foreach ($lote['threads'] ?? [] as $hilo) {
                $this->importarHilo($instance, $sync, $hilo, $telefonoNegocio);
            }

            $this->avanzar($sync, $meta);
        }
    }

    /** Un hilo del historial: un contacto y todos sus mensajes del lote. */
    private function importarHilo(
        Instance $instance,
        CoexistenceSync $sync,
        array $hilo,
        string $telefonoNegocio
    ): void {
        $waId = WhatsAppConversation::normalizePhone($hilo['id'] ?? '');

        if ($waId === '') {
            return;
        }

        $conversacion = WhatsAppConversation::resolveFor($instance->id, $waId, [
            'phone_number' => $waId,
            'name' => $waId,
            'status' => 'open',
        ]);

        $nuevos = 0;
        $ultimo = null;

        foreach ($hilo['messages'] ?? [] as $mensaje) {
            $guardado = $this->guardarMensaje(
                $conversacion,
                $mensaje,
                $telefonoNegocio,
                $mensaje['history_context']['status'] ?? null
            );

            if ($guardado === null) {
                continue;
            }

            $nuevos++;

            if ($ultimo === null || $guardado->sent_at?->gt($ultimo->sent_at ?? now()->subCentury())) {
                $ultimo = $guardado;
            }
        }

        if ($nuevos === 0) {
            return;
        }

        // El historial llega desordenado y por fases hacia atrás en el tiempo.
        // Tocar `last_message` con cualquier mensaje dejaría el listado de chats
        // mostrando como "último" algo de hace seis meses.
        if ($ultimo && (
            $conversacion->last_message_at === null
            || $ultimo->sent_at?->gt($conversacion->last_message_at)
        )) {
            $conversacion->update([
                'last_message' => $ultimo->metadata['resumen'] ?? $ultimo->content ?: 'Archivo adjunto',
                'last_message_at' => $ultimo->sent_at,
            ]);
        }

        $this->registrarContacto($instance, $conversacion, $waId);

        $sync->increment('messages_imported', $nuevos);
        $sync->increment('conversations_touched');
    }

    // ------------------------------------------------------------- contactos

    /**
     * La agenda del celular del negocio.
     *
     * Llega una vez al importar y después cada vez que el negocio agrega o
     * edita un contacto en su teléfono.
     */
    public function sincronizarContactos(Instance $instance, array $value): void
    {
        $sync = $this->sync($instance);
        $nuevos = 0;

        foreach ($value['state_sync'] ?? [] as $cambio) {
            if (($cambio['type'] ?? null) !== 'contact') {
                continue;
            }

            $telefono = WhatsAppConversation::normalizePhone($cambio['contact']['phone_number'] ?? '');

            if ($telefono === '') {
                continue;
            }

            // Un `remove` significa que el negocio borró el contacto de la
            // agenda de su teléfono, no que quiera perderlo del CRM: aquí
            // cuelgan conversaciones, etiquetas y negocios. Se registra y no se
            // toca nada.
            if (($cambio['action'] ?? 'add') === 'remove') {
                Log::channel('whatsapp')->info('👤 Contacto eliminado en el celular (no se borra del CRM)', [
                    'instance_id' => $instance->id,
                    'phone' => $telefono,
                ]);

                continue;
            }

            $nombre = $cambio['contact']['full_name']
                ?? $cambio['contact']['first_name']
                ?? $telefono;

            $contacto = Contact::where('company_id', $instance->company_id)
                ->where(function ($q) use ($telefono) {
                    $q->where('phone_number', $telefono)
                        ->orWhere('phone_numbers', 'like', '%"'.$telefono.'"%');
                })
                ->first();

            if (! $contacto) {
                Contact::create([
                    'company_id' => $instance->company_id,
                    'phone_number' => $telefono,
                    'name' => $nombre,
                ]);
                $nuevos++;

                continue;
            }

            // El nombre de la agenda del negocio es mejor que el del perfil de
            // WhatsApp, pero no pisa un nombre que alguien haya escrito a mano.
            if (in_array((string) $contacto->name, ['', 'Desconocido', $telefono], true)) {
                $contacto->update(['name' => $nombre]);
            }
        }

        if ($nuevos > 0) {
            $sync->increment('contacts_imported', $nuevos);
        }

        if (in_array($sync->status, [CoexistenceSync::PENDIENTE, CoexistenceSync::SOLICITADA], true)) {
            $sync->update(['status' => CoexistenceSync::IMPORTANDO, 'first_chunk_at' => now()]);
        }

        // Los contactos son lo primero que ve moverse el cliente: llegan antes
        // que el historial y son los que ponen nombres donde había números.
        $this->emitir($sync);
    }

    // ------------------------------------------------------------------ ecos

    /**
     * Lo que el negocio responde desde la app del celular.
     *
     * Sin esto el asesor abre el CRM, ve la pregunta del cliente sin respuesta y
     * contesta otra vez algo que ya se respondió por el teléfono.
     */
    public function reflejarEco(Instance $instance, array $value): void
    {
        $telefonoNegocio = WhatsAppConversation::normalizePhone(
            $value['metadata']['display_phone_number'] ?? $instance->display_phone_number
        );

        foreach ($value['message_echoes'] ?? [] as $eco) {
            $waId = WhatsAppConversation::normalizePhone($eco['to'] ?? '');

            if ($waId === '') {
                continue;
            }

            $conversacion = WhatsAppConversation::resolveFor($instance->id, $waId, [
                'phone_number' => $waId,
                'name' => $waId,
                'status' => 'open',
            ]);

            $guardado = $this->guardarMensaje($conversacion, $eco, $telefonoNegocio, 'SENT');

            if ($guardado === null) {
                continue;
            }

            $conversacion->update([
                'last_message' => $guardado->metadata['resumen'] ?? $guardado->content ?: 'Archivo adjunto',
                'last_message_at' => $guardado->sent_at ?? now(),
            ]);

            $this->registrarContacto($instance, $conversacion, $waId);
        }
    }

    // --------------------------------------------------------------- comunes

    /**
     * Guarda un mensaje si no estaba ya.
     *
     * Devuelve el mensaje creado, o null si ya existía o no se pudo interpretar.
     */
    private function guardarMensaje(
        WhatsAppConversation $conversacion,
        array $mensaje,
        string $telefonoNegocio,
        ?string $estadoMeta
    ): ?WhatsAppMessage {
        $wamid = $mensaje['id'] ?? null;

        if (! $wamid) {
            return null;
        }

        $de = WhatsAppConversation::normalizePhone($mensaje['from'] ?? '');
        // `from_me` es lo que manda el volcado; `from` sólo aparece en algunos
        // lotes y en los ecos. Se miran los dos, en ese orden.
        $saliente = $mensaje['history_context']['from_me']
            ?? ($de !== '' && $de === $telefonoNegocio);

        // Una reacción no es una burbuja: se cuelga del mensaje al que apunta.
        // Si el original no está (quedó fuera del rango importado) se descarta,
        // que es mejor que dejar un "Mensaje no compatible" suelto en el hilo.
        if (($mensaje['type'] ?? null) === 'reaction') {
            $this->aplicarReaccion($conversacion, $mensaje);

            return null;
        }

        $contenido = $this->interpretar($mensaje, (bool) $saliente);

        $datos = array_merge($contenido, [
            'conversation_id' => $conversacion->id,
            'wamid' => $wamid,
            'direction' => $saliente ? 'outbound' : 'inbound',
            'status' => self::ESTADOS[strtoupper((string) $estadoMeta)] ?? 'delivered',
            // La zona va explícita. `createFromTimestamp` sin ella devuelve el
            // Carbon en UTC, y la columna se escribe tal cual: cada eco del
            // celular quedaba cinco horas por delante de su propio `created_at`.
            // Como este `sent_at` se copia luego a `last_message_at`, la lista
            // de conversaciones enseñaba una hora futura y colaba ese chat
            // arriba del todo. Mismo arreglo que ya lleva el webhook de
            // entrantes en WhatsAppWebhookController.
            'sent_at' => isset($mensaje['timestamp'])
                ? Carbon::createFromTimestamp((int) $mensaje['timestamp'], config('app.timezone'))
                : now(),
        ]);

        if (isset($mensaje['context']['id'])) {
            $datos['reply_to_wamid'] = $mensaje['context']['id'];
        }

        // `firstOrCreate` sobre una columna única: si Meta reenvía el lote, la
        // segunda vuelta no crea nada y `wasRecentlyCreated` lo delata.
        $guardado = WhatsAppMessage::firstOrCreate(['wamid' => $wamid], $datos);

        return $guardado->wasRecentlyCreated ? $guardado : null;
    }

    /**
     * Traduce el cuerpo del mensaje a las columnas de la tabla.
     *
     * Se cubren los tipos que de verdad aparecen en un historial; lo que no se
     * reconozca se guarda entero en `metadata` para poder darle soporte después
     * sin haber perdido el contenido.
     */
    private function interpretar(array $mensaje, bool $saliente = false): array
    {
        $tipo = $mensaje['type'] ?? 'text';

        return match ($tipo) {
            'text' => [
                'type' => 'text',
                'content' => $mensaje['text']['body'] ?? '',
            ],

            'image', 'video', 'audio', 'document', 'sticker' => [
                'type' => $tipo,
                'content' => $mensaje[$tipo]['caption'] ?? null,
                'media_id' => $mensaje[$tipo]['id'] ?? null,
                'media_url' => $mensaje[$tipo]['url'] ?? null,
                'media_mime_type' => $mensaje[$tipo]['mime_type'] ?? null,
                'filename' => $mensaje[$tipo]['filename'] ?? null,
            ],

            // El contenido real llega en un webhook posterior, y sólo para los
            // mensajes de las dos últimas semanas. Hasta entonces el chat
            // muestra que hubo un archivo, en vez de un hueco.
            'media_placeholder' => [
                'type' => 'media_placeholder',
                'content' => 'Archivo adjunto',
                'metadata' => ['pendiente_de_media' => true],
            ],

            'location' => [
                'type' => 'location',
                'content' => $mensaje['location']['name'] ?? 'Ubicación',
                'metadata' => ['location' => $mensaje['location'] ?? []],
            ],

            'contacts' => [
                'type' => 'contacts',
                'content' => 'Contacto compartido',
                'metadata' => ['contacts' => $mensaje['contacts'] ?? []],
            ],

            // Respuesta a un botón de plantilla. El texto viene en el propio
            // payload: guardarlo como "no compatible" era tirar a la basura lo
            // que el cliente contestó ("Aceptar", "Cancelar"…).
            'button' => [
                'type' => 'text',
                'content' => $mensaje['button']['text'] ?? $mensaje['button']['payload'] ?? 'Botón',
                'metadata' => ['button' => $mensaje['button'] ?? []],
            ],

            'interactive' => [
                'type' => 'text',
                'content' => $mensaje['interactive']['button_reply']['title']
                    ?? $mensaje['interactive']['list_reply']['title']
                    ?? 'Respuesta interactiva',
                'metadata' => ['interactive' => $mensaje['interactive'] ?? []],
            ],

            'order' => [
                'type' => 'text',
                'content' => trim('🛒 Pedido con '.count($mensaje['order']['product_items'] ?? []).' producto(s)'
                    .(! empty($mensaje['order']['text']) ? ": {$mensaje['order']['text']}" : '')),
                'metadata' => ['order' => $mensaje['order'] ?? []],
            ],

            // Meta no supo representar el mensaje (llamadas, invitaciones a
            // canal, vista única, encuestas…). El contenido no viene y no hay
            // forma de pedirlo: lo único honesto es decir que sigue en el
            // celular. Ver App\Support\MensajeNoEntregado.
            'unsupported', 'errors' => MensajeNoEntregado::columnas($mensaje, $saliente, true),

            default => MensajeNoEntregado::columnas(
                array_merge($mensaje, ['unsupported' => ['type' => $tipo]]),
                $saliente,
                true
            ),
        };
    }

    /** Cuelga el emoji de una reacción del mensaje al que apunta. */
    private function aplicarReaccion(WhatsAppConversation $conversacion, array $mensaje): void
    {
        $destino = $mensaje['reaction']['message_id'] ?? null;
        $emoji = $mensaje['reaction']['emoji'] ?? null;

        if (! $destino) {
            return;
        }

        $original = WhatsAppMessage::where('wamid', $destino)
            ->where('conversation_id', $conversacion->id)
            ->first();

        if (! $original) {
            return;
        }

        $meta = $original->metadata ?? [];

        if ($emoji === null || $emoji === '') {
            unset($meta['reaction']);
        } else {
            $meta['reaction'] = $emoji;
        }

        $original->metadata = $meta;
        $original->save();
    }

    /**
     * Completa un mensaje multimedia cuyo contenido llegó después.
     *
     * Sólo actualiza si el mensaje existe y sigue siendo un marcador: si el
     * webhook se repite, la segunda vuelta no encuentra nada que hacer.
     */
    private function completarMultimedia(array $mensajes): void
    {
        foreach ($mensajes as $mensaje) {
            $wamid = $mensaje['id'] ?? null;

            if (! $wamid) {
                continue;
            }

            $existente = WhatsAppMessage::where('wamid', $wamid)
                ->where('type', 'media_placeholder')
                ->first();

            if (! $existente) {
                continue;
            }

            $existente->update($this->interpretar($mensaje));
        }
    }

    /** Da de alta el contacto del hilo si la conversación aún no tiene uno. */
    private function registrarContacto(
        Instance $instance,
        WhatsAppConversation $conversacion,
        string $telefono
    ): void {
        if ($conversacion->contact_id) {
            return;
        }

        $contacto = Contact::firstOrCreate(
            ['company_id' => $instance->company_id, 'phone_number' => $telefono],
            ['name' => $conversacion->name ?: $telefono]
        );

        $conversacion->update(['contact_id' => $contacto->id]);
    }

    /**
     * Mueve el progreso con lo que Meta reporta.
     *
     * `progress` es el porcentaje **de la fase**, no del total, y los lotes
     * llegan desordenados (`chunk_order` existe justo por eso). Sólo se avanza,
     * nunca se retrocede: una barra que baja parece un fallo.
     */
    private function avanzar(CoexistenceSync $sync, array $meta): void
    {
        $fase = (int) ($meta['phase'] ?? $sync->phase);

        // Ausente no es cero. Un lote sin `progress` no dice «vamos por 0%»,
        // dice que no trae el dato: tomarlo por cero es lo que dejaba la
        // importación de Transintermet en fase 2 al 100 pero con el estado
        // todavía en «importando» (9-sep-2026).
        $progreso = array_key_exists('progress', $meta) ? (int) $meta['progress'] : null;

        // `last_chunk_at` se escribe en cada lote, aunque no cambie ni la fase
        // ni el progreso: es lo que permite distinguir después una importación
        // que sigue viva de una que se quedó muda a mitad.
        $cambios = ['last_chunk_at' => now()];

        if ($sync->first_chunk_at === null) {
            $cambios['first_chunk_at'] = now();
        }

        if ($fase > $sync->phase) {
            $cambios['phase'] = $fase;
            // Fase nueva, cuenta nueva: aquí la ausencia sí es empezar de cero.
            $cambios['progress'] = $progreso ?? 0;
        } elseif ($fase === $sync->phase && $progreso !== null && $progreso > $sync->progress) {
            $cambios['progress'] = $progreso;
        }

        // El estado se decide sobre el resultado acumulado, no sobre el lote que
        // acaba de llegar. Los lotes vienen desordenados —para eso existe
        // `chunk_order`— y decidiéndolo con el lote suelto, uno rezagado
        // reabría una importación que ya había terminado.
        $faseFinal = $cambios['phase'] ?? $sync->phase;
        $progresoFinal = $cambios['progress'] ?? $sync->progress;

        if ($faseFinal >= 2 && $progresoFinal >= 100) {
            // Sin volver a escribir `completed_at`: la importación terminó
            // cuando terminó, no cada vez que llega un rezagado.
            if ($sync->status !== CoexistenceSync::COMPLETADA) {
                $cambios['status'] = CoexistenceSync::COMPLETADA;
                $cambios['completed_at'] = now();
            }
        } elseif (! $sync->terminada()) {
            // Sólo se marca «importando» lo que no estaba ya cerrado: quien
            // eligió no compartir su historial no vuelve a estar importando
            // porque llegue un lote suelto.
            $cambios['status'] = CoexistenceSync::IMPORTANDO;
        }

        $sync->update($cambios);

        $this->emitir($sync);
    }

    /**
     * Manda el estado a la pantalla del cliente.
     *
     * Nunca debe tumbar la importación: si Reverb está caído, el contenido ya
     * se guardó y la consulta de respaldo lo recogerá igual. Lo único que se
     * pierde es la inmediatez.
     */
    private function emitir(CoexistenceSync $sync): void
    {
        try {
            CoexistenceSyncEvent::dispatch($sync->fresh());
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('📡 No se pudo emitir el progreso de coexistencia', [
                'instance_id' => $sync->instance_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** La fila de sincronización de la instancia, creándola si hiciera falta. */
    private function sync(Instance $instance): CoexistenceSync
    {
        // Sin `requested_at`: esa marca significa "ya gastamos el único intento
        // de importación" y sólo la pone quien de verdad se lo pide a Meta. Un
        // eco del celular puede crear esta fila antes, y ponerla aquí dejaría
        // al cliente sin historial sin que nadie se enterase.
        return CoexistenceSync::firstOrCreate(['instance_id' => $instance->id]);
    }
}
