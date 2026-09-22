<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\BandejaDeMessenger;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El webhook del tópico `page`.
 *
 * Va en su propia URL por lo mismo que el de Instagram: Meta admite un solo
 * `callback_url` por app y por tópico, y `page`, `instagram` y
 * `whatsapp_business_account` son tres suscripciones independientes que
 * conviven en la misma app sin estorbarse.
 *
 * La diferencia que importa está en el otro extremo: en `page` **no basta con
 * suscribir el tópico**. Cada página tiene que apuntarse además a la app
 * (`/{page-id}/subscribed_apps`, lo hace `MessengerLoginService` al conectar),
 * y sin ese paso aquí no llega nada aunque la suscripción del tópico esté
 * perfecta.
 */
class MessengerWebhookController extends Controller
{
    public function __construct(
        private MetaWhatsAppService $metaService,
        private BandejaDeMessenger $bandeja,
    ) {}

    /**
     * El apretón de manos: Meta llama con `hub.challenge` y hay que devolverlo
     * tal cual, en texto plano.
     */
    public function verificar(Request $request)
    {
        $modo = $this->parametro($request, 'hub_mode', 'hub.mode');
        $token = $this->parametro($request, 'hub_verify_token', 'hub.verify_token');
        $desafio = $this->parametro($request, 'hub_challenge', 'hub.challenge');

        $esperado = config('services.meta.messenger.verify_token');

        if ($modo === 'subscribe' && is_string($esperado) && $esperado !== '' && hash_equals($esperado, (string) $token)) {
            Log::channel('messenger')->info('✅ Webhook de Messenger verificado');

            return response($desafio, 200)->header('Content-Type', 'text/plain');
        }

        Log::channel('messenger')->warning('❌ Verificación del webhook de Messenger rechazada', [
            'modo' => $modo,
            'ip' => $request->ip(),
        ]);

        return response('Forbidden', 403);
    }

    public function recibir(Request $request)
    {
        // Sobre el cuerpo crudo, nunca sobre $request->all() re-serializado: no
        // reproduce byte a byte lo que firmó Meta.
        //
        // El validador prueba todos los secretos de META_APP_SECRETS y por eso
        // sirve aquí sin tocarlo: la clave de la app de Instagram es una entrada
        // más de esa lista. Si falta, esto responde 403 y no entra nada — que es
        // la causa clásica de «se desplegó y dejaron de llegar mensajes».
        if (! $this->metaService->validateWebhookSignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            Log::channel('messenger')->warning('❌ Webhook de Messenger rechazado: firma inválida', [
                'ip' => $request->ip(),
                'tiene_firma' => $request->header('X-Hub-Signature-256') !== null,
            ]);

            return response('Forbidden', 403);
        }

        foreach ($request->input('entry', []) as $entrada) {
            // Instagram y Messenger traen `messaging[]`; WhatsApp trae
            // `changes[].value.messages[]`. Se parecen y no son intercambiables.
            foreach ($entrada['messaging'] ?? [] as $evento) {
                try {
                    $this->procesarMensaje($entrada['id'] ?? null, $evento);
                } catch (\Throwable $e) {
                    // Un evento que reviente no debe tumbar los demás del lote.
                    Log::channel('messenger')->error('❌ Error procesando evento de Messenger', [
                        'pagina' => $entrada['id'] ?? null,
                        'mensaje' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Un evento suelto del tópico `instagram`, a la bandeja.
     *
     * `entry.id` es la **página** que recibió el mensaje, y es lo que dice de
     * qué empresa es: por este mismo callback entran los eventos de todas las
     * páginas conectadas, así que sin esta búsqueda un mensaje podría caer en
     * la bandeja de otro cliente.
     */
    private function procesarMensaje(?string $pagina, array $evento): void
    {
        Log::channel('messenger')->info('📩 Evento de Messenger recibido', [
            'pagina' => $pagina,
            'de' => $evento['sender']['id'] ?? null,
            'para' => $evento['recipient']['id'] ?? null,
            // El texto NO se vuelca: es conversación de un cliente final y el
            // log no es el sitio. Sólo si lo hubo y de qué tipo.
            'tipo' => $this->tipoDeEvento($evento),
            'mid' => $evento['message']['mid'] ?? null,
        ]);

        if (! $pagina) {
            return;
        }

        $linea = Instance::canal(Instance::CANAL_MESSENGER)
            ->where('external_account_id', $pagina)
            ->first();

        if (! $linea) {
            // Pasa de verdad: una página que se desconectó de nuestro lado pero
            // sigue suscrita en Meta, o un evento de pruebas. Se registra y se
            // deja pasar; devolver error haría que Meta reintente para siempre
            // un mensaje que no tiene dónde ir.
            Log::channel('messenger')->warning('⚠️ Evento de una página que no tenemos conectada', [
                'pagina' => $pagina,
            ]);

            return;
        }

        $this->bandeja->guardar($linea, $evento);
    }

    private function tipoDeEvento(array $evento): string
    {
        return match (true) {
            isset($evento['message']['text']) => 'texto',
            isset($evento['message']['attachments']) => 'adjunto',
            isset($evento['reaction']) => 'reaccion',
            isset($evento['postback']) => 'postback',
            isset($evento['read']) => 'leido',
            default => 'otro',
        };
    }

    /**
     * PHP convierte los puntos de la query en guiones bajos, así que `hub.mode`
     * llega como `hub_mode`. Se miran los dos nombres y, si el servidor se
     * comió la query, se reconstruye desde REQUEST_URI: le pasó al webhook de
     * WhatsApp en este mismo despliegue y costó una verificación fallida.
     */
    private function parametro(Request $request, string $conGuion, string $conPunto): ?string
    {
        $valor = $request->query($conGuion) ?? $request->query($conPunto);

        if ($valor !== null) {
            return (string) $valor;
        }

        $query = parse_url((string) $request->server('REQUEST_URI'), PHP_URL_QUERY);

        if (! $query) {
            return null;
        }

        parse_str($query, $parametros);

        $valor = $parametros[$conGuion] ?? $parametros[$conPunto] ?? null;

        return $valor === null ? null : (string) $valor;
    }
}
