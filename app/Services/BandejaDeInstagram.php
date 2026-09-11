<?php

namespace App\Services;

use App\Events\ConversationEvent;
use App\Events\WhatsAppMessageEvent;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\Realtime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Lo que llega por Instagram, puesto en la misma bandeja que WhatsApp.
 *
 * No pasa por `WhatsAppWebhookController::processChange()` a propósito. Aquel
 * método arrastra medio producto —menús, respuestas automáticas, horario
 * comercial, opt-out, cambios de número, permisos de llamada— y casi nada de eso
 * existe en Instagram: no hay plantillas, no hay campañas, no hay número que
 * cambie. Meterlo por ahí habría obligado a poner condicionales de canal en
 * todas esas ramas, y cada una es un sitio donde romper WhatsApp, que es lo que
 * usan once clientes en producción.
 *
 * Lo que sí se comparte es lo que importa: los mismos modelos, la misma
 * conversación, los mismos eventos en tiempo real. Para la bandeja, un mensaje
 * de Instagram es un mensaje.
 */
class BandejaDeInstagram
{
    /**
     * Guarda un evento del tópico `instagram` y devuelve el mensaje, si lo hubo.
     *
     * Devuelve null cuando el evento no es un mensaje —un «visto», una entrega,
     * una reacción— y eso no es un fallo: son la mayoría del tráfico.
     */
    public function guardar(Instance $linea, array $evento): ?WhatsAppMessage
    {
        $mid = $evento['message']['mid'] ?? null;

        if (! $mid) {
            return null;
        }

        // `is_echo` marca lo que la propia empresa envió, ya sea desde nuestro
        // CRM o desde la app de Instagram en el móvil. Se guarda igual —si no,
        // el asesor vería el hilo a medias— pero como saliente y sin contar como
        // no leído.
        $esEco = (bool) ($evento['message']['is_echo'] ?? false);

        // En un eco, el «otro» es el destinatario; en un entrante, el emisor.
        $cliente = $esEco
            ? ($evento['recipient']['id'] ?? null)
            : ($evento['sender']['id'] ?? null);

        if (! $cliente) {
            return null;
        }

        // Idempotencia por `wamid`, igual que en WhatsApp: Meta reintenta lotes
        // enteros y nuestro propio envío vuelve además como eco con el mismo
        // mid. Sin esto, cada respuesta saldría dos veces en el chat.
        if ($existente = WhatsAppMessage::where('wamid', $mid)->first()) {
            return $existente;
        }

        $conversacion = WhatsAppConversation::resolverPorIdentidad($linea->id, $cliente, [
            'name' => $this->nombreDelCliente($evento, $cliente),
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        [$tipo, $contenido, $media] = $this->contenido($evento['message'] ?? []);

        $mensaje = WhatsAppMessage::create(array_filter([
            'conversation_id' => $conversacion->id,
            'wamid' => $mid,
            'reply_to_wamid' => $evento['message']['reply_to']['mid'] ?? null,
            'type' => $tipo,
            'content' => $contenido,
            'media_url' => $media,
            'direction' => $esEco ? 'outbound' : 'inbound',
            'status' => $esEco ? 'sent' : 'delivered',
            // Instagram manda el timestamp en milisegundos, no en segundos. Sin
            // dividir, `sent_at` se va a dentro de cincuenta mil años y la
            // ventana de 24 h se da por abierta para siempre.
            'sent_at' => isset($evento['timestamp'])
                ? Carbon::createFromTimestampMs((int) $evento['timestamp'], config('app.timezone'))
                : now(),
        ], fn ($valor) => $valor !== null));

        $reabierta = $conversacion->status === 'closed';

        $cambios = [
            'last_message' => $contenido ?? ucfirst($tipo),
            'last_message_at' => now(),
        ];

        // Si el cliente vuelve a escribir, un hilo cerrado se reabre para que no
        // quede escondido en «Cerradas». El rastro del cierre se limpia con él:
        // si no, el panel mostraría «cerrada por X» en un chat abierto.
        if ($reabierta) {
            $cambios['status'] = 'open';
            $cambios['closed_by'] = null;
            $cambios['closed_at'] = null;
        }

        $conversacion->update($cambios);

        if (! $esEco) {
            $conversacion->incrementUnread();
        }

        $this->enTiempoReal($mensaje, $linea, $conversacion, $reabierta);

        return $mensaje;
    }

    /**
     * Qué tipo de mensaje es y qué se enseña en el chat.
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function contenido(array $mensaje): array
    {
        if (isset($mensaje['text'])) {
            return ['text', (string) $mensaje['text'], null];
        }

        $adjunto = $mensaje['attachments'][0] ?? null;

        if (! $adjunto) {
            return ['text', null, null];
        }

        $url = $adjunto['payload']['url'] ?? null;

        // Los tipos de Instagram no son los nuestros uno a uno: `ig_reel` y
        // `share` son publicaciones compartidas por privado, y en el chat se ven
        // mejor como un enlace que como un archivo roto.
        $tipo = match ($adjunto['type'] ?? '') {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            'file' => 'document',
            default => 'text',
        };

        // Un adjunto sin URL no se puede pintar, así que al menos se dice qué
        // llegó en vez de dejar una burbuja vacía.
        if ($tipo === 'text' || ! $url) {
            return ['text', $this->descripcion($adjunto), $url];
        }

        return [$tipo, null, $url];
    }

    private function descripcion(array $adjunto): string
    {
        return match ($adjunto['type'] ?? '') {
            'ig_reel' => '🎬 Compartió un reel',
            'share' => '🔗 Compartió una publicación',
            'story_mention' => '📸 Te mencionó en una historia',
            'like_heart' => '❤️',
            default => 'Contenido de Instagram no compatible',
        };
    }

    /**
     * Cómo se llama quien escribe.
     *
     * Instagram no manda el nombre en el webhook —a diferencia de WhatsApp, que
     * trae el `profile.name`—, así que de entrada se pone el IGSID recortado. Es
     * feo pero honesto, y se sustituye cuando el asesor abre el chat y pedimos
     * el perfil.
     */
    private function nombreDelCliente(array $evento, string $cliente): string
    {
        return $evento['sender']['username'] ?? 'Instagram · '.substr($cliente, -6);
    }

    /**
     * Empuja el mensaje a los agentes conectados.
     *
     * Si Reverb no responde el mensaje ya está guardado, así que sólo se avisa:
     * el poll del chat lo recogerá igual. Perder el aviso en vivo no puede
     * costar el mensaje.
     */
    private function enTiempoReal(
        WhatsAppMessage $mensaje,
        Instance $linea,
        WhatsAppConversation $conversacion,
        bool $reabierta
    ): void {
        try {
            broadcast(new WhatsAppMessageEvent($mensaje->load('sender'), $linea->id, 'new'));

            // La fila de la conversación va aparte del mensaje: el evento de
            // mensaje sólo sabe parchear una fila que el agente YA tenga en su
            // lista, y una cuenta que escribe por primera vez no está en ella.
            Realtime::push(ConversationEvent::updated($conversacion, $reabierta ? 'reopened' : 'message'));
        } catch (\Throwable $e) {
            Log::channel('instagram')->warning('⚠️ No se pudo emitir el mensaje de Instagram en tiempo real', [
                'mensaje' => $mensaje->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
