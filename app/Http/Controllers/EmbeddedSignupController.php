<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registro insertado de Meta (Embedded Signup).
 *
 * Es la ventana oficial donde el cliente conecta SU cuenta de WhatsApp: entra
 * con su Facebook, elige su empresa y su número, autoriza, y Meta nos devuelve
 * los identificadores y un código canjeable. Sustituye al pegado manual de
 * phone_number_id, waba_id y token, que es como se conectaron las empresas
 * hasta ahora y sigue estando disponible por si esto falla.
 *
 * Lo que hace posible esto es el acceso avanzado que Meta aprobó el 2026-09-05:
 * sin él la app sólo podía operar activos propios, no los de un cliente.
 *
 * El navegador nunca ve el secreto de la app ni el token del cliente: recibe un
 * código de un solo uso y lo manda aquí, y el canje ocurre servidor a servidor.
 *
 * Hay dos caminos y no son intercambiables: el normal rechaza cualquier número
 * que ya tenga WhatsApp, y el de coexistencia es el único que admite el número
 * que el negocio ya usa a diario. Cuál aplica, qué le cambia al cliente en su
 * celular y qué hacer cuando falla está en docs/conexion-whatsapp.md.
 */
class EmbeddedSignupController extends Controller
{
    public function __construct(private MetaWhatsAppService $meta)
    {
    }

    /**
     * GET /api/embedded-signup/config — lo que el navegador necesita para abrir
     * la ventana.
     *
     * Nada de aquí es secreto: el id de la app y el de la configuración viajan
     * igual dentro del JavaScript. Si falta cualquiera de las tres piezas se
     * responde `enabled: false` y la pantalla no muestra el botón, en vez de
     * mostrar uno que abriría una ventana rota.
     */
    public function config()
    {
        $appId = config('services.meta.app_id');
        $configId = config('services.meta.embedded_signup_config_id');
        // El secreto puede venir de META_APP_SECRETS (por app) y no del
        // singular, que en producción está vacío. Se pregunta por el mismo
        // camino que usará el canje, para que la pantalla no prometa un botón
        // que el servidor no puede honrar.
        $hasSecret = (bool) $this->meta->appSecretFor($appId);

        return response()->json([
            'enabled'     => (bool) ($appId && $configId && $hasSecret),
            'app_id'      => $appId,
            'config_id'   => $configId,
            'api_version' => config('services.meta.api_version', 'v21.0'),
        ]);
    }

    /**
     * POST /api/embedded-signup — termina el onboarding con lo que devolvió la
     * ventana y deja la instancia lista.
     *
     * Los tres pasos son los que exige Meta para un Tech Provider: canjear el
     * código por el token del cliente, suscribir nuestra app a su WABA (sin eso
     * no llega un solo webhook) y guardar la instancia.
     *
     * Lo que NO se hace aquí es registrar el número en Cloud API: eso pide un
     * PIN de dos pasos, puede fallar por causas ajenas y ya tiene su propio
     * botón en la pantalla de WhatsApp. Meterlo aquí convertiría un fallo de
     * registro en "no se conectó nada", cuando en realidad la cuenta ya quedó
     * vinculada y sólo falta un paso reintentable.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'code'            => 'required|string',
            'waba_id'         => 'required|string|max:64',
            'phone_number_id' => 'required|string|max:64',
        ]);

        $user = auth()->user();

        // Un phone_number_id activo en dos instancias hace que los mensajes
        // entrantes lleguen sólo a una. Se corta antes de gastar el código,
        // que es de un solo uso: si se canjea y luego falla la validación, el
        // cliente tendría que repetir toda la ventana.
        $ocupado = Instance::where('phone_number_id', $data['phone_number_id'])
            ->where('active', true)
            ->first();

        if ($ocupado) {
            return response()->json([
                'message' => $ocupado->company_id === $user->company_id
                    ? 'Ese número ya está conectado en esta cuenta.'
                    : 'Ese número ya está conectado en otra cuenta. Escríbenos para moverlo.',
            ], 422);
        }

        $exchange = $this->meta->exchangeSignupCode($data['code']);

        if (! ($exchange['success'] ?? false)) {
            return response()->json([
                'message' => 'No se pudo completar la conexión con Meta: ' . ($exchange['error'] ?? 'error desconocido'),
            ], 422);
        }

        $token = $exchange['token'];

        // Sin esto la cuenta queda vinculada pero muda: los mensajes que le
        // escriban al cliente no llegarían nunca a nuestro callback.
        $subscription = $this->meta->subscribeApp($data['waba_id'], $token);

        if (! ($subscription['success'] ?? false)) {
            Log::error('Embedded Signup: no se pudo suscribir la app al WABA', [
                'company_id' => $user->company_id,
                'waba_id'    => $data['waba_id'],
                'error'      => $subscription['error'] ?? null,
            ]);

            return response()->json([
                'message' => 'La cuenta se autorizó, pero no se pudo suscribir para recibir mensajes. Vuelve a intentarlo.',
            ], 422);
        }

        // El nombre y el número son para que la instancia se reconozca en la
        // lista. Si Meta no los da, la conexión sigue siendo válida y se usa un
        // nombre provisional que el admin puede cambiar.
        $detalle = $this->meta->getPhoneNumber($data['phone_number_id'], $token);
        $numero = $detalle['data'] ?? [];

        $instance = Instance::create([
            'company_id'           => $user->company_id,
            'uuid'                 => Str::uuid(),
            'name'                 => $numero['verified_name'] ?? 'WhatsApp',
            'phone_number_id'      => $data['phone_number_id'],
            'waba_id'              => $data['waba_id'],
            'display_phone_number' => $numero['display_phone_number'] ?? null,
            'access_token'         => $token,
            'type'                 => 'meta',
            'status'               => 'active',
            'active'               => true,
        ]);

        Log::info('Embedded Signup: instancia conectada', [
            'company_id'  => $user->company_id,
            'instance_id' => $instance->id,
            'waba_id'     => $data['waba_id'],
        ]);

        return response()->json([
            'message'  => 'Cuenta de WhatsApp conectada.',
            'instance' => [
                'id'                   => $instance->id,
                'name'                 => $instance->name,
                'display_phone_number' => $instance->display_phone_number,
            ],
        ]);
    }
}
