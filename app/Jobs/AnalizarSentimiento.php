<?php

namespace App\Jobs;

use App\Events\ConversationEvent;
use App\Models\CompanyExtension;
use App\Support\PlanDeLaEmpresa;
use App\Models\SentimentEvent;
use App\Models\WhatsAppConversation;
use App\Services\SentimientoIaClient;
use App\Support\Realtime;
use App\Support\Sentimiento\Lectura;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Afina con IA el color que ya puso la matriz.
 *
 * ## Por qué tiene cola propia
 *
 * Hasta ahora había **una sola cola** y la IA de menús ya la monopoliza: dos
 * workers y hasta 210 segundos por inferencia. Meter aquí una inferencia por
 * mensaje entrante habría dejado los envíos de WhatsApp detrás de una fila de
 * análisis de sentimiento — y un mensaje que sale tarde es un problema de
 * verdad, mientras que un semáforo que tarda en afinarse no lo es: el color de
 * la matriz ya está puesto.
 *
 * Por eso `sentimiento` y no `default`. **Necesita su propio worker**: un
 * `queue:work` sin `--queue` sólo atiende `default`, así que sin el servicio del
 * compose estos jobs se quedan encolados para siempre sin un solo error.
 *
 * ## Por qué un solo intento
 *
 * Reintentar una inferencia cuesta otra inferencia para llegar, casi siempre, al
 * mismo sitio. Y no hay nada que salvar: si falla, el color de la matriz se
 * queda, que es un resultado aceptable.
 *
 * El `$timeout` tiene que caber en el `retry_after` de la cola (360): por debajo,
 * Redis le entregaría a otro worker un job que éste sigue ejecutando.
 */
class AnalizarSentimiento implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    /**
     * Ids escalares y no modelos: entre el dispatch y la ejecución puede pasar
     * de todo —el agente contesta, el chat se cierra—, y lo que hay que mirar es
     * el estado de ahora, no el de hace dos minutos.
     */
    public function __construct(public int $conversationId)
    {
        $this->timeout = (int) config('services.sentimiento.timeout', 45) + 15;
        $this->onQueue('sentimiento');
    }

    public function handle(SentimientoIaClient $ia): void
    {
        $conversation = WhatsAppConversation::with('instance.company')->find($this->conversationId);

        if (! $conversation?->instance) {
            return;
        }

        // El agente pudo corregir el color mientras esto esperaba turno. Su
        // criterio manda siempre.
        if ($conversation->sentiment_locked_by !== null) {
            return;
        }

        $instalada = CompanyExtension::where('company_id', $conversation->instance->company_id)
            ->where('slug', 'sentiment_traffic_light')
            ->where('enabled', true)
            ->first();

        // Se revalida después de la espera y no sólo al despachar: la empresa
        // pudo apagar la extensión, o la IA, mientras el job hacía cola.
        if (! $instalada || ! ($instalada->settings()['usar_ia'] ?? false)) {
            return;
        }

        // Y el plan, que el candado de instalación no cubre: `usar_ia` pudo
        // quedar encendido de antes de que la empresa perdiera el complemento.
        // Sin esto seguía llamando al modelo y gastando tokens de alguien que no
        // lo paga. El semáforo no se apaga —la capa de léxico sigue coloreando
        // sin modelo—, sólo deja de afinarse.
        if (! PlanDeLaEmpresa::de($conversation->instance->company)->permiteAjuste('sentiment_traffic_light', 'usar_ia')) {
            return;
        }

        $mensajes = $conversation->messages()
            ->where('type', 'text')
            ->where('is_internal', false)
            ->orderByDesc('id')
            ->limit((int) ($instalada->settings()['ventana_mensajes'] ?? 8))
            ->get(['id', 'content', 'direction'])
            ->all();

        // Con los dos lados de la conversación, no sólo el cliente: "llevo tres
        // días esperando" significa una cosa si nadie contestó y otra si el
        // agente lleva tres días dando explicaciones.
        $anterior = $conversation->sentiment_level;

        $lectura = $ia->analizar(
            $conversation->instance,
            $conversation,
            $mensajes,
            $conversation->sentiment_level
                ? new Lectura(
                    $conversation->sentiment_level,
                    (float) $conversation->sentiment_score,
                    (string) $conversation->sentiment_reason,
                )
                : null
        );

        if (! $lectura) {
            return;
        }

        // Se apunta después de que el modelo respondiera: lo que se factura es
        // el uso que sirvió para algo. Una llamada que falló ya nos costó, pero
        // cobrársela al cliente por un fallo nuestro es otra cosa.
        \App\Support\ContadorDeIa::apuntar(
            $conversation->instance->company_id,
            'semaforo'
        );

        SentimentEvent::registrar(
            $conversation,
            $conversation->instance->company_id,
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

        Log::channel('whatsapp')->info('🎨 Semáforo afinado por la IA', [
            'conversation_id' => $conversation->id,
            'company_id' => $conversation->instance->company_id,
            'de' => $anterior,
            'a' => $lectura->nivel,
            'confianza' => $lectura->confianza,
        ]);

        if ($lectura->cambiaRespectoA($anterior)) {
            Realtime::push(ConversationEvent::updated($conversation, 'sentimiento'));
        }
    }
}
