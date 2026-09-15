<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le pide a un modelo el resumen de una conversación.
 *
 * Mismo camino que los otros clientes de IA del proyecto: un webhook de n8n con
 * `X-Api-Key`, y **decide y devuelve** — no escribe en la base ni habla con
 * Meta. Quien llama es el único que persiste.
 *
 * **Degrada a null y nunca lanza.** A diferencia del semáforo, aquí el fallo sí
 * lo ve una persona: alguien pulsó un botón y está esperando. Por eso el que
 * llama distingue "no configurado" de "falló", y por eso el timeout es corto —
 * un agente que espera 45 segundos mirando un botón ya ha abierto la
 * conversación y la ha leído él.
 */
class ResumenIaClient
{
    /** ¿Tiene la plataforma este flujo configurado? */
    public static function configured(): bool
    {
        return filled(config('services.resumen.webhook_url'))
            && filled(config('services.resumen.api_key'));
    }

    /**
     * @param  list<WhatsAppMessage>  $mensajes  De más antiguo a más reciente.
     * @return array{resumen: string, puntos: list<string>, pendientes: list<string>}|null
     */
    public function resumir(
        Instance $instance,
        WhatsAppConversation $conversation,
        array $mensajes,
        string $tono = 'neutro'
    ): ?array {
        if (! self::configured() || $mensajes === []) {
            return null;
        }

        try {
            $respuesta = Http::acceptJson()
                ->withHeaders(['X-Api-Key' => (string) config('services.resumen.api_key')])
                ->timeout((int) config('services.resumen.timeout', 30))
                ->post((string) config('services.resumen.webhook_url'), [
                    'empresa' => [
                        'id' => $instance->company_id,
                        'nombre' => $instance->company->name ?? '',
                    ],
                    'conversacion' => [
                        'id' => $conversation->id,
                        'contacto' => $conversation->name,
                    ],
                    'tono' => $tono,
                    // El texto va tal cual, sin recortar por palabras: un
                    // resumen que no ve la última frase del cliente resume otra
                    // conversación. Quien llama ya limita cuántos mensajes manda.
                    'mensajes' => array_map(fn (WhatsAppMessage $m) => [
                        'de' => $m->direction === 'inbound' ? 'cliente' : 'asesor',
                        'texto' => (string) $m->content,
                        'cuando' => optional($m->sent_at ?? $m->created_at)->toIso8601String(),
                    ], $mensajes),
                ]);

            if ($respuesta->failed()) {
                Log::channel('whatsapp')->warning('⚠️ El resumen con IA no respondió', [
                    'conversacion' => $conversation->id,
                    'estado' => $respuesta->status(),
                ]);

                return null;
            }

            return $this->leer($respuesta->json());
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ El resumen con IA falló', [
                'conversacion' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Saca las tres piezas de lo que devuelva el modelo.
     *
     * Tolerante a propósito: n8n puede envolver la respuesta en `output`, en
     * `data` o devolverla pelada, y el modelo a veces manda las listas como
     * texto con saltos de línea. Un resumen es información, no una orden que se
     * ejecute, así que es mejor aprovechar lo que llegue que descartarlo entero
     * por la forma del sobre.
     *
     * @return array{resumen: string, puntos: list<string>, pendientes: list<string>}|null
     */
    private function leer(mixed $json): ?array
    {
        $datos = $json['output'] ?? $json['data'] ?? $json;

        if (! is_array($datos)) {
            return null;
        }

        $resumen = trim((string) ($datos['resumen'] ?? $datos['summary'] ?? ''));

        if ($resumen === '') {
            return null;
        }

        return [
            // Un resumen que ocupa más que la conversación no es un resumen.
            'resumen' => mb_substr($resumen, 0, 2000),
            'puntos' => $this->lista($datos['puntos'] ?? $datos['highlights'] ?? []),
            'pendientes' => $this->lista($datos['pendientes'] ?? $datos['todo'] ?? []),
        ];
    }

    /** @return list<string> */
    private function lista(mixed $valor): array
    {
        if (is_string($valor)) {
            $valor = preg_split('/\r?\n/', $valor) ?: [];
        }

        if (! is_array($valor)) {
            return [];
        }

        $limpia = [];

        foreach ($valor as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            // Se quita la viñeta si el modelo la trae: la pinta el frontend.
            $texto = trim(preg_replace('/^\s*[-*•·]\s*/u', '', (string) $item));

            if ($texto !== '') {
                $limpia[] = mb_substr($texto, 0, 200);
            }
        }

        return array_slice($limpia, 0, 8);
    }
}
