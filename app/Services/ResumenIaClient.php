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
     * ¿Responde la IA de la plataforma? Un resumen de mentira de dos mensajes.
     *
     * Recorre el camino real —CRM → n8n → Ollama— y no una puerta aparte: lo
     * que se quiere saber es si el resumen funciona, y un «ping» a Ollama
     * diría que sí aunque n8n estuviera caído. Cuesta una inferencia mínima al
     * día. Nació el 28-sep-2026: Ollama rechazaba todo por el pago vencido y
     * se supo porque lo contó un cliente.
     *
     * @return array{ok: bool, estado: ?int, detalle: ?string}
     */
    public function probar(): array
    {
        if (! self::configured()) {
            return ['ok' => false, 'estado' => null, 'detalle' => 'El servicio de resumen no está configurado.'];
        }

        try {
            $respuesta = Http::acceptJson()
                ->withHeaders(['X-Api-Key' => (string) config('services.resumen.api_key')])
                ->timeout((int) config('services.resumen.timeout', 30))
                ->post((string) config('services.resumen.webhook_url'), [
                    // Ids que no existen pero no son cero: el flujo rechaza un id
                    // vacío —«falta conversacion.id»— antes de llegar a Ollama, y
                    // la prueba se quedaba sin probar nada.
                    'empresa' => ['id' => self::ID_DE_PRUEBA, 'nombre' => 'Comprobación diaria'],
                    'conversacion' => ['id' => self::ID_DE_PRUEBA, 'contacto' => 'Prueba'],
                    'tono' => 'telegrama',
                    'mensajes' => [
                        ['de' => 'cliente', 'texto' => 'Hola, ¿ya quedó registrado mi pago?', 'cuando' => now()->toIso8601String()],
                        ['de' => 'asesor', 'texto' => 'Sí, quedó registrado hoy.', 'cuando' => now()->toIso8601String()],
                    ],
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'estado' => null, 'detalle' => $e->getMessage()];
        }

        if ($respuesta->failed()) {
            return ['ok' => false, 'estado' => $respuesta->status(), 'detalle' => mb_substr($respuesta->body(), 0, 300)];
        }

        if ($this->leer($respuesta->json()) !== null) {
            return ['ok' => true, 'estado' => $respuesta->status(), 'detalle' => null];
        }

        // El flujo a veces contesta 200 con el motivo en `error`: se pasa tal
        // cual, que es lo que dice si el fallo es de Ollama o del propio flujo.
        $error = data_get($respuesta->json(), 'error') ?? data_get($respuesta->json(), 'output.error');

        return ['ok' => false, 'estado' => $respuesta->status(), 'detalle' => $error ? (string) $error : 'Respondió, pero sin resumen.'];
    }

    /** Ni empresa ni conversación: el id con que viaja la comprobación diaria. */
    public const ID_DE_PRUEBA = 999999999;

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

        // Este es el único fallo del resumen que no dejaba rastro: el flujo
        // respondía 200, el cliente devolvía null y la pantalla decía «no se
        // pudo resumir» sin que hubiera nada que mirar. Pasó de verdad —el
        // modelo agotaba `num_predict` razonando y mandaba `content` vacío— y
        // costó media hora encontrarlo leyendo ejecuciones de n8n a mano.
        if ($resumen === '') {
            Log::channel('whatsapp')->warning('⚠️ El resumen con IA llegó sin texto', [
                'claves' => array_keys($datos),
                'muestra' => mb_substr(json_encode($datos, JSON_UNESCAPED_UNICODE) ?: '', 0, 300),
            ]);

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
