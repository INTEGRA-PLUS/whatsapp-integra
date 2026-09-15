<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\Sentimiento\Lectura;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * La capa 2 del semáforo: le pregunta a un modelo cómo está el cliente.
 *
 * Existe porque la capa 1 tiene techo. Medidos contra conversaciones reales de
 * atención al cliente, los métodos de léxico rinden alrededor del 50 % y los
 * modelos de lenguaje pasan del 85 %: la diferencia está en todo lo que no es
 * una palabra suelta —la ironía, la frase larga, el "todo bien" que significa
 * lo contrario—.
 *
 * **Degrada a null y nunca lanza.** Si n8n no responde, si el modelo devuelve
 * basura o si la empresa no lo tiene encendido, el color que ya puso la matriz
 * se queda como está. El semáforo no puede depender de que esto funcione: es la
 * razón de que la capa 1 corra primero y escriba siempre.
 *
 * Como los otros dos clientes de IA, decide y devuelve: no escribe en la base ni
 * habla con Meta.
 *
 * @see \App\Support\Sentimiento\Semaforo La capa 1.
 */
class SentimientoIaClient
{
    /** ¿Tiene la plataforma este flujo configurado? */
    public static function configured(): bool
    {
        return filled(config('services.sentimiento.webhook_url'))
            && filled(config('services.sentimiento.api_key'));
    }

    /**
     * @param list<WhatsAppMessage> $mensajes De más reciente a más antiguo.
     */
    public function analizar(
        Instance $instance,
        WhatsAppConversation $conversation,
        array $mensajes,
        ?Lectura $matriz
    ): ?Lectura {
        if (! self::configured() || $mensajes === []) {
            return null;
        }

        try {
            $respuesta = Http::acceptJson()
                ->withHeaders(['X-Api-Key' => (string) config('services.sentimiento.api_key')])
                ->timeout((int) config('services.sentimiento.timeout', 45))
                ->post((string) config('services.sentimiento.webhook_url'), [
                    'empresa' => [
                        'id' => $instance->company_id,
                        'nombre' => $instance->company->name ?? '',
                    ],
                    'conversacion' => [
                        'id' => $conversation->id,
                        'nombre' => $conversation->name,
                    ],
                    // El flujo recibe el veredicto de la matriz, no para
                    // obedecerlo sino para poder discrepar con criterio: "el
                    // léxico dice rojo por la palabra cancelar" es contexto útil
                    // cuando la frase era "no quiero cancelar, todo bien".
                    'matriz' => $matriz ? [
                        'nivel' => $matriz->nivel,
                        'score' => $matriz->score,
                        'motivo' => $matriz->motivo,
                    ] : null,
                    'mensajes' => collect($mensajes)
                        ->map(fn (WhatsAppMessage $m) => [
                            'rol' => $m->direction === 'inbound' ? 'cliente' : 'agente',
                            'texto' => mb_substr((string) $m->content, 0, 1000),
                        ])
                        ->values()
                        ->all(),
                ]);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ El flujo de sentimiento no respondió', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $respuesta->successful()) {
            Log::channel('whatsapp')->warning('⚠️ El flujo de sentimiento rechazó la petición', [
                'conversation_id' => $conversation->id,
                'status' => $respuesta->status(),
                'body' => mb_substr((string) $respuesta->body(), 0, 300),
            ]);

            return null;
        }

        return $this->traducir($respuesta->json() ?? [], $conversation);
    }

    /**
     * Traduce lo que devuelva n8n, sin fiarse de nada.
     *
     * Un color que no reconocemos NO se convierte en verde: se descarta la
     * respuesta entera y manda la matriz. Ese default es el que convierte un
     * fallo del flujo en un cliente furioso marcado como tranquilo, que es
     * exactamente el error que más caro sale.
     */
    private function traducir(array $datos, WhatsAppConversation $conversation): ?Lectura
    {
        $cuerpo = isset($datos[0]) && is_array($datos[0]) ? $datos[0] : $datos;

        $nivel = is_string($cuerpo['color'] ?? null) ? mb_strtolower(trim($cuerpo['color'])) : '';

        if (! in_array($nivel, Lectura::NIVELES, true)) {
            Log::channel('whatsapp')->info('ℹ️ El flujo de sentimiento devolvió un color desconocido', [
                'conversation_id' => $conversation->id,
                'color' => $cuerpo['color'] ?? null,
            ]);

            return null;
        }

        $confianza = (float) ($cuerpo['confianza'] ?? 0);

        // Por debajo del umbral se queda la matriz. Un modelo que duda no aporta
        // sobre un léxico que al menos es predecible y explicable.
        if ($confianza < 0.6) {
            return null;
        }

        $motivo = trim((string) ($cuerpo['motivo'] ?? ''));

        return new Lectura(
            $nivel,
            match ($nivel) {
                Lectura::ROJO => -0.8,
                Lectura::AMARILLO => -0.35,
                default => 0.4,
            },
            $motivo !== '' ? mb_substr($motivo, 0, 200) : 'Según el análisis de la conversación',
            Lectura::ORIGEN_IA,
            min(1.0, $confianza),
        );
    }
}
