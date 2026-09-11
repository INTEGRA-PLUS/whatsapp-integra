<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\BandejaDeInstagram;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El webhook del tópico `instagram`.
 *
 * Va en su propia URL y no colgando del de WhatsApp porque Meta admite un solo
 * `callback_url` por app y por tópico: son dos suscripciones independientes que
 * conviven en la misma app.
 */
class InstagramWebhookController extends Controller
{
    public function __construct(
        private MetaWhatsAppService $metaService,
        private BandejaDeInstagram $bandeja,
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

        $esperado = config('services.meta.instagram.verify_token');

        if ($modo === 'subscribe' && is_string($esperado) && $esperado !== '' && hash_equals($esperado, (string) $token)) {
            Log::channel('instagram')->info('✅ Webhook de Instagram verificado');

            return response($desafio, 200)->header('Content-Type', 'text/plain');
        }

        Log::channel('instagram')->warning('❌ Verificación del webhook de Instagram rechazada', [
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
            Log::channel('instagram')->warning('❌ Webhook de Instagram rechazado: firma inválida', [
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
                    Log::channel('instagram')->error('❌ Error procesando evento de Instagram', [
                        'cuenta' => $entrada['id'] ?? null,
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
     * `entry.id` es la cuenta profesional que recibió el mensaje, y es lo que
     * dice de qué empresa es: una app de Tech Provider recibe eventos de todas
     * las cuentas conectadas por el mismo callback, así que sin esta búsqueda un
     * mensaje podría caer en la bandeja de otro cliente.
     */
    private function procesarMensaje(?string $cuenta, array $evento): void
    {
        Log::channel('instagram')->info('📩 Evento de Instagram recibido', [
            'cuenta' => $cuenta,
            'de' => $evento['sender']['id'] ?? null,
            'para' => $evento['recipient']['id'] ?? null,
            // El texto NO se vuelca: es conversación de un cliente final y el
            // log no es el sitio. Sólo si lo hubo y de qué tipo.
            'tipo' => $this->tipoDeEvento($evento),
            'mid' => $evento['message']['mid'] ?? null,
        ]);

        if (! $cuenta) {
            return;
        }

        $linea = Instance::canal(Instance::CANAL_INSTAGRAM)
            ->where('external_account_id', $cuenta)
            ->first();

        if (! $linea) {
            // Pasa de verdad: una cuenta que se desconectó de nuestro lado pero
            // sigue suscrita en Meta, o un evento de pruebas. Se registra y se
            // deja pasar; devolver error haría que Meta reintente para siempre
            // un mensaje que no tiene dónde ir.
            Log::channel('instagram')->warning('⚠️ Evento de una cuenta de Instagram que no tenemos conectada', [
                'cuenta' => $cuenta,
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
