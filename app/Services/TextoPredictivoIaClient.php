<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le pide a un modelo tres formas de contestar el mensaje que el asesor tiene
 * delante.
 *
 * Mismo camino que los otros clientes de IA del proyecto: un webhook de n8n con
 * `X-Api-Key`, y **decide y devuelve** — no escribe en la base, no habla con
 * Meta y, sobre todo, no envía nada. Quien llama es el único que persiste, y
 * quien pulsa enviar es una persona.
 *
 * **Degrada a lista vacía y nunca lanza.** El fallo aquí lo ve alguien delante
 * de la pantalla, pero no le rompe nada: sin sugerencias el asesor escribe como
 * escribía ayer. Por eso el timeout es el más corto de los tres flujos — a los
 * veinticinco segundos la sugerencia ya no sirve, porque para entonces él ya
 * tecleó la frase.
 *
 * ## Lo que este cliente NO se cree
 *
 * El flujo de n8n ya comprueba, número a número, que ninguna sugerencia traiga
 * una cifra que no esté en la conversación. **Aquí se vuelve a comprobar**, y no
 * es paranoia barata: el flujo se importa a mano, se le puede desenganchar un
 * nodo sin querer, y alguien puede apuntar `PREDICTIVO_WEBHOOK_URL` a otro sitio
 * en cinco segundos. Lo que cuesta un `preg_match` aquí es que un precio
 * inventado no llegue nunca al campo de texto de un asesor con prisa.
 *
 * @see \App\Extensions\TextoPredictivoExtension Por qué sugiere y no envía.
 */
class TextoPredictivoIaClient
{
    /** Tope duro por sugerencia. El flujo recorta a 320; esto es el cinturón. */
    private const MAX_CARACTERES = 400;

    /** ¿Tiene la plataforma este flujo configurado? */
    public static function configured(): bool
    {
        return filled(config('services.texto_predictivo.webhook_url'))
            && filled(config('services.texto_predictivo.api_key'));
    }

    /**
     * @param  list<WhatsAppMessage>  $mensajes  De más reciente a más antiguo.
     * @return list<array{etiqueta: string, texto: string}>
     */
    public function sugerir(
        Instance $instance,
        WhatsAppConversation $conversation,
        array $mensajes,
        int $cuantas = 3,
        string $instrucciones = '',
        string $borrador = ''
    ): array {
        if (! self::configured() || $mensajes === []) {
            return [];
        }

        try {
            $respuesta = Http::acceptJson()
                ->withHeaders(['X-Api-Key' => (string) config('services.texto_predictivo.api_key')])
                ->timeout((int) config('services.texto_predictivo.timeout', 25))
                ->post((string) config('services.texto_predictivo.webhook_url'), [
                    'empresa' => [
                        'id' => $instance->company_id,
                        'nombre' => $instance->company->name ?? '',
                    ],
                    'conversacion' => [
                        'id' => $conversation->id,
                        'nombre' => $conversation->name,
                    ],
                    // El bloque entrenable de la empresa. Va como dato, no como
                    // prompt: quien lo monta —con su delimitador y con las reglas
                    // innegociables repetidas detrás— es el nodo «Preparar
                    // sugerencias» del flujo, igual que en el gateway de chats.
                    'asistente' => [
                        'instrucciones' => $instrucciones,
                    ],
                    // Lo que el asesor ya llevaba escrito, si algo. No es
                    // contexto de adorno: sin esto, pedir sugerencias a media
                    // frase devuelve tres respuestas que empiezan de cero y
                    // ninguna sirve para continuar la que estaba escribiendo.
                    'borrador' => mb_substr($borrador, 0, 500),
                    'cuantas' => $cuantas,
                    'mensajes' => array_map(fn (WhatsAppMessage $m) => [
                        'rol' => $m->direction === 'inbound' ? 'cliente' : 'agente',
                        'texto' => mb_substr((string) $m->content, 0, 1000),
                    ], $mensajes),
                ]);

            if ($respuesta->failed()) {
                Log::channel('whatsapp')->warning('⚠️ El texto predictivo no respondió', [
                    'conversacion' => $conversation->id,
                    'estado' => $respuesta->status(),
                ]);

                return [];
            }

            return $this->leer($respuesta->json(), $conversation, $mensajes, $cuantas);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ El texto predictivo falló', [
                'conversacion' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Saca las sugerencias de lo que devuelva el flujo, y desconfía de todas.
     *
     * @param  list<WhatsAppMessage>  $mensajes
     * @return list<array{etiqueta: string, texto: string}>
     */
    private function leer(
        mixed $json,
        WhatsAppConversation $conversation,
        array $mensajes,
        int $cuantas
    ): array {
        // n8n envuelve la respuesta de tres maneras distintas según cómo esté
        // montado el nodo de salida: pelada, dentro de `output`/`data`, o como
        // array de un elemento. Las tres se han visto en los otros flujos.
        $datos = is_array($json) && isset($json[0]) && is_array($json[0]) ? $json[0] : $json;
        $datos = $datos['output'] ?? $datos['data'] ?? $datos;

        if (! is_array($datos)) {
            return [];
        }

        $crudas = $datos['sugerencias'] ?? [];

        if (! is_array($crudas) || $crudas === []) {
            // El único fallo de este flujo que no dejaba rastro: responde 200,
            // el asesor ve «no se me ocurre nada» y no hay nada que mirar. El
            // flujo manda `descartado` y `error_modelo` justo para esto, y se
            // registran sólo esos dos campos — nunca el texto, que parafrasea la
            // conversación del cliente y no tiene por qué acabar en el log.
            Log::channel('whatsapp')->info('ℹ️ El texto predictivo no devolvió sugerencias', [
                'conversacion' => $conversation->id,
                'descartado' => $datos['descartado'] ?? null,
                'error_modelo' => $datos['error_modelo'] ?? null,
            ]);

            return [];
        }

        $permitidas = $this->cifrasDe(implode(' ', array_map(
            fn (WhatsAppMessage $m) => (string) $m->content,
            $mensajes
        )));

        $limpias = [];

        foreach ($crudas as $cruda) {
            if (! is_array($cruda)) {
                continue;
            }

            $texto = trim((string) ($cruda['texto'] ?? ''));

            if ($texto === '' || mb_strlen($texto) > self::MAX_CARACTERES) {
                continue;
            }

            // LA COMPROBACIÓN QUE JUSTIFICA ESTE MÉTODO, otra vez. El flujo ya
            // la hizo; ésta es la que queda en pie si el flujo se cambia, se
            // reimporta a medias o deja de ser el nuestro. Una sugerencia con
            // una cifra que nadie dijo no se recorta ni se corrige: se tira
            // entera, porque a una frase a la que le quitas el número deja de
            // querer decir lo que decía.
            $inventadas = array_diff($this->cifrasDe($texto), $permitidas);

            if ($inventadas !== []) {
                Log::channel('whatsapp')->warning('⚠️ El texto predictivo devolvió una cifra que no está en la conversación', [
                    'conversacion' => $conversation->id,
                    'cifras' => array_slice(array_values($inventadas), 0, 3),
                ]);

                continue;
            }

            $limpias[] = [
                'etiqueta' => mb_substr(trim((string) ($cruda['etiqueta'] ?? '')), 0, 24) ?: 'Sugerencia',
                'texto' => $texto,
            ];

            if (count($limpias) >= $cuantas) {
                break;
            }
        }

        return $limpias;
    }

    /**
     * Toda cifra de un texto, reducida a sus dígitos.
     *
     * Se compara por dígitos y no literalmente porque el modelo reformatea: el
     * cliente escribe «120000» y la sugerencia dice «120.000». Eso no es
     * inventarse un dato, es escribirlo bien.
     *
     * @return list<string>
     */
    private function cifrasDe(string $texto): array
    {
        preg_match_all('/[0-9][0-9.,:\/-]*/u', $texto, $coincidencias);

        $cifras = array_filter(array_map(
            fn (string $c) => preg_replace('/\D/', '', $c),
            $coincidencias[0] ?? []
        ));

        return array_values(array_unique($cifras));
    }
}
