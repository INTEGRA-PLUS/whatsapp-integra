<?php

namespace App\Extensions;

use App\Events\ConversationEvent;
use App\Extensions\Contracts\HandlesInboundMessage;
use App\Jobs\AnalizarSentimiento;
use App\Models\CompanyExtension;
use App\Models\SentimentEvent;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\Realtime;
use App\Support\Sentimiento\Lectura;
use App\Support\Sentimiento\Semaforo;
use App\Services\SentimientoIaClient;
use Illuminate\Support\Facades\Cache;

/**
 * Un semáforo por conversación: cómo está el cliente, de un vistazo.
 *
 * La bandeja ordena por hora de llegada, que no es lo mismo que por urgencia. El
 * cliente que lleva tres mensajes hablando de cancelar se ve exactamente igual
 * que el que escribió "gracias, listo", y hay que abrir los dos para saber cuál
 * es cuál. El "esperando respuesta" que ya existe mide el reloj; esto mide el
 * ánimo.
 *
 * ## La regla de calibración, que es lo que hace que sirva
 *
 * El color NO responde a "¿este cliente tiene un problema?" —todos lo tienen,
 * por eso escriben— sino a **"¿necesita atención antes que los demás?"**. Un
 * cliente que reporta una avería con calma está en verde. El amarillo y el rojo
 * se ganan: insistir, esperar, enfadarse, hablar de irse.
 *
 * Sin esa calibración el semáforo se pone rojo entero el primer día y a la
 * semana nadie lo mira, que es el modo de fallo documentado de estas
 * herramientas.
 *
 * ## Lo que NO hace
 *
 * No avisa a nadie, no reasigna, no etiqueta y no apaga el bot. Marca y ya. Es
 * deliberado: un semáforo que dispara acciones hay que acertarlo antes de
 * encenderlo, y para acertarlo hace falta verlo funcionando sobre
 * conversaciones reales. Cuando esté calibrado, las acciones son un ajuste más.
 *
 * Tampoco juzga al agente: mide al cliente, no la calidad de quien atiende.
 *
 * @see Semaforo Dónde se decide el color.
 */
class SentimientoExtension extends Extension implements HandlesInboundMessage
{
    public function __construct(private Semaforo $semaforo) {}

    public function slug(): string
    {
        return 'sentiment_traffic_light';
    }

    public function name(): string
    {
        return 'Semáforo de emociones';
    }

    public function description(): string
    {
        return 'Marca cada conversación en verde, amarillo o rojo según cómo esté el cliente.';
    }

    public function detail(): string
    {
        return 'Lee los mensajes del cliente y pinta un punto de color en la lista de chats: '
            .'verde si no hay fricción, amarillo si insiste o lleva esperando, rojo si está '
            .'enfadado o habla de irse. Al lado del punto se explica por qué.'
            ."\n\n"
            .'Tener un problema no pone a nadie en rojo: quien reporta una avería con calma sigue '
            .'en verde. El color no dice "este cliente tiene una incidencia" —todos la tienen—, '
            .'dice "a éste atiéndelo antes".'
            ."\n\n"
            .'Pesa más lo reciente que lo antiguo, y se enfada rápido pero se calma despacio: un '
            .'"gracias" no borra tres mensajes de enfado. Además de las palabras cuenta lo que no '
            .'se escribe, como llevar varios mensajes seguidos sin que nadie conteste.'
            ."\n\n"
            .'Sólo mira mensajes de texto del cliente; no analiza audios, imágenes ni documentos. '
            .'No avisa a nadie, no reasigna y no le responde nada al cliente: sólo marca. Y no '
            .'entiende la ironía, así que un "excelente servicio" dicho con retintín le sale '
            .'positivo.';
    }

    public function icon(): string
    {
        return 'Gauge';
    }

    public function category(): string
    {
        return self::CATEGORIA_CONVERSACIONES;
    }

    public function permissions(): array
    {
        return [
            'Leer el texto de los mensajes entrantes',
            'Marcar el estado de ánimo en tus conversaciones',
        ];
    }

    public function hooks(): array
    {
        return ['Al llegar un mensaje de texto del cliente'];
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'sensibilidad',
                'type' => 'select',
                'label' => 'Sensibilidad',
                'help' => 'Con «alta» se marcan más conversaciones en amarillo y rojo. Empieza en '
                    .'media y súbela sólo si ves que se te escapan clientes molestos.',
                'default' => 'medio',
                'options' => [
                    ['value' => 'bajo', 'label' => 'Baja — sólo lo evidente'],
                    ['value' => 'medio', 'label' => 'Media'],
                    ['value' => 'alto', 'label' => 'Alta — avisa antes'],
                ],
            ],
            [
                'key' => 'usar_ia',
                'type' => 'boolean',
                'label' => 'Afinar con IA',
                'help' => 'El diccionario decide al instante y la IA repasa después las '
                    .'conversaciones dudosas: entiende frases largas y sarcasmo, que el '
                    .'diccionario no. Cuesta una consulta al modelo. Si la IA falla o se apaga, '
                    .'el semáforo sigue funcionando igual.',
                'default' => false,
            ],
            [
                'key' => 'ventana_mensajes',
                'type' => 'number',
                'label' => 'Mensajes que mira',
                'help' => 'Cuántos mensajes recientes del cliente pesan en el color. Más mensajes '
                    .'es más memoria: la conversación tarda más en volver a verde.',
                'default' => 8,
                'min' => 3,
                'max' => 20,
            ],
            [
                'key' => 'palabras_rojas',
                'type' => 'textarea',
                'label' => 'Palabras propias de tu negocio',
                'help' => 'Una por línea. Términos que en TU empresa son mala señal y que el '
                    .'diccionario general no conoce: el nombre de un producto que siempre da '
                    .'problemas, una expresión local, el nombre de un competidor.',
                'default' => '',
            ],
        ];
    }

    public function sanitizeSettings(array $input, int $companyId): array
    {
        $sensibilidad = $input['sensibilidad'] ?? null;
        $ventana = (int) ($input['ventana_mensajes'] ?? 8);

        return [
            'sensibilidad' => in_array($sensibilidad, ['bajo', 'medio', 'alto'], true)
                ? $sensibilidad
                : 'medio',
            'usar_ia' => (bool) ($input['usar_ia'] ?? false),
            'ventana_mensajes' => max(3, min(20, $ventana)),
            // Se recorta aquí y no sólo en el formulario porque estas filas
            // también se escriben desde tinker, y cada línea acaba siendo una
            // búsqueda más sobre cada mensaje entrante dentro del webhook.
            'palabras_rojas' => mb_substr(trim((string) ($input['palabras_rojas'] ?? '')), 0, 2000),
        ];
    }

    public function onInboundMessage(
        WhatsAppConversation $conversation,
        WhatsAppMessage $message,
        CompanyExtension $installed
    ): void {
        if ($message->type !== 'text' || trim((string) $message->content) === '') {
            return;
        }

        // Si un agente corrigió el color a mano, manda él. La corrección humana
        // es además la única etiqueta fiable que vamos a tener para calibrar
        // esto: pisarla sería borrar el único dato bueno del sistema.
        if ($conversation->sentiment_locked_by !== null) {
            return;
        }

        $lectura = $this->semaforo->leer($conversation, $installed->settings());

        if (! $lectura) {
            return;
        }

        $anterior = $conversation->sentiment_level;
        $cambio = $lectura->cambiaRespectoA($anterior);

        SentimentEvent::registrar(
            $conversation,
            $installed->company_id,
            $lectura->nivel,
            $lectura->score,
            $lectura->origen,
            $anterior
        );

        $conversation->update([
            'sentiment_level' => $lectura->nivel,
            'sentiment_score' => $lectura->score,
            'sentiment_reason' => $lectura->motivo,
            'sentiment_source' => $lectura->origen,
            'sentiment_at' => now(),
        ]);

        // Sólo se emite cuando el color cambia de verdad. El webhook ya mandó un
        // `ConversationEvent` por este mismo mensaje unas líneas antes; repetirlo
        // en cada mensaje para decir "sigue en verde" sería doblar el tráfico de
        // Reverb sin que cambie un pixel.
        if ($cambio) {
            Realtime::push(ConversationEvent::updated($conversation, 'sentimiento'));
        }

        $this->afinarConIa($conversation, $installed, $lectura);
    }

    /**
     * Manda la conversación a la capa 2, si toca.
     *
     * Las tres condiciones son de coste, no de corrección: el semáforo ya está
     * puesto y lo que se decide aquí es si merece la pena gastar una inferencia.
     *
     * - **En verde no se pregunta.** Es el caso mayoritario con diferencia y el
     *   que menos se gana repasando: el coste de equivocarse en un verde
     *   tranquilo es bajo, y preguntarlo todo multiplicaría la factura por diez
     *   para cambiar de opinión en una fracción de los casos.
     * - **Una vez cada `debounce` segundos por conversación.** Sin esto, una
     *   ráfaga de seis mensajes seguidos —lo normal en WhatsApp cuando alguien
     *   está molesto— son seis inferencias para decidir el mismo color.
     * - **Con candado**, no sólo con marca de tiempo: dos mensajes que entran a
     *   la vez pasarían los dos la comprobación del reloj.
     */
    private function afinarConIa(
        WhatsAppConversation $conversation,
        CompanyExtension $installed,
        Lectura $lectura
    ): void {
        if (! ($installed->settings()['usar_ia'] ?? false) || ! SentimientoIaClient::configured()) {
            return;
        }

        if ($lectura->nivel === Lectura::VERDE) {
            return;
        }

        $espera = (int) config('services.sentimiento.debounce', 120);

        if (! Cache::add('sentimiento:conv:'.$conversation->id, true, $espera)) {
            return;
        }

        AnalizarSentimiento::dispatch($conversation->id);
    }
}
