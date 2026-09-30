<?php

namespace App\Http\Controllers;

use App\Services\MessengerLoginService;
use App\Models\Contact;
use App\Models\Company;
use App\Models\Instance;
use App\Services\InstagramLoginService;
use App\Services\RegistrarLineaEnIntegra;
use App\Services\LineaDeEnvioEnIntegra;
use App\Models\CompanyIntegration;
use App\Support\IntegrationProvider;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InstanceController extends Controller
{
    public function __construct()
    {
        // $this->middleware('auth'); // Middleware is usually applied in routes in Laravel 11
    }

    public function index()
    {
        $user = auth()->user();

        if ($user->isMaster() && ! session('impersonated_by')) {
            return redirect()->route('master.index');
        }

        $instances = Instance::where('company_id', $user->company_id)
            ->with('coexistenceSync')
            ->orderBy('created_at', 'desc')
            ->get();

        // El estado de la importación viaja con la página para que la tarjeta
        // esté pintada en el primer render: si sólo llegara por websocket, un
        // cliente que recarga a mitad vería una pantalla vacía y pensaría que
        // el proceso se perdió.
        $sincronizaciones = $instances
            ->pluck('coexistenceSync')
            ->filter()
            ->map(fn ($sync) => $sync->paraPantalla())
            ->values();

        return Inertia::render('Instances/Index', [
            'instances' => $instances->makeHidden('coexistenceSync'),
            'coexistenceSyncs' => $sincronizaciones,
            // Sin App ID ni clave de Instagram el botón sólo llevaría a un error
            // de Meta, así que no se pinta. Mismo criterio que el del registro
            // insertado de WhatsApp.
            'instagramDisponible' => app(InstagramLoginService::class)->estaConfigurado(),
            'messengerDisponible' => app(\App\Services\MessengerLoginService::class)->estaConfigurado(),
            // Cuántas líneas de cada canal le deja conectar su plan. La
            // pantalla lo usa para no abrir la ventana de Meta cuando el
            // servidor la va a rechazar al volver.
            'cupos' => $user->company ? PlanDeLaEmpresa::de($user->company)->cupos() : null,
            // Para el recuadro de Integra de cada tarjeta: si hay software
            // conectado y por cuál de las líneas factura.
            'integra' => [
                'conectado' => CompanyIntegration::where('company_id', $user->company_id)
                    ->whereIn('key', IntegrationProvider::find(IntegrationProvider::INTEGRA)['legacy_keys'] ?? [])
                    ->get()
                    ->contains(fn (CompanyIntegration $i) => $i->isConnected()),
                'linea_de_envio' => $user->company?->instanciaDelErp()?->id,
            ],
        ]);
    }

    /**
     * «Sincronizar con Integra»: dejar allá esta línea como la de envío.
     *
     * Es el arreglo a mano de cuando las dos puntas no coinciden y cada factura
     * vuelve con «Instancia no válida o token ausente» (Nova Partners,
     * 24-sep-2026). Sólo para la línea por la que ya factura el CRM: cambiar de
     * línea es otra decisión —comprueba que la nueva tenga las plantillas— y se
     * toma en Integraciones, no con un botón de sincronizar.
     */
    public function sincronizarConIntegra(Instance $instance)
    {
        abort_unless($instance->company_id === auth()->user()->company_id, 403);

        if (! $instance->active || ! $instance->esWhatsApp() || ! $instance->phone_number_id) {
            return response()->json([
                'message' => 'Sólo una línea de WhatsApp conectada puede enviar las facturas de Integra.',
            ], 422);
        }

        if (auth()->user()->company->instanciaDelErp()?->id !== $instance->id) {
            return response()->json([
                'message' => 'Integra envía las facturas por otra línea. Para cambiarla, elígela en '
                    .'Integraciones › Por dónde envía Integra.',
            ], 422);
        }

        $res = app(LineaDeEnvioEnIntegra::class)($instance);

        return response()->json(['ok' => $res['ok'], 'message' => $res['mensaje']], $res['ok'] ? 200 : 422);
    }

    /**
     * Estado de la importación de una instancia.
     *
     * Es el respaldo de la vía por websocket: si Reverb no conecta —proxies,
     * redes de oficina que cierran los websockets— la barra tiene que seguir
     * avanzando igual. Devuelve exactamente la misma forma que el evento.
     */
    public function coexistenceSync(Instance $instance)
    {
        abort_unless($instance->company_id === auth()->user()->company_id, 403);

        $sync = $instance->coexistenceSync;

        // Un `null` a secas se serializa como `{}`, que en JavaScript es
        // verdadero: la pantalla pintaría una tarjeta de progreso vacía para
        // una instancia que nunca tuvo importación. Se responde siempre con la
        // misma llave para que el cliente pueda decidir sin ambigüedad.
        return response()->json(['sync' => $sync?->paraPantalla()]);
    }

    /**
     * Un phone_number_id solo puede estar activo en una instancia: el webhook
     * identifica la empresa por ese campo, así que si dos lo comparten todos los
     * mensajes entrantes se guardan en la primera y la otra empresa no recibe
     * nada. El índice único de la tabla es (company_id, phone_number_id), que no
     * impide el choque entre empresas distintas.
     */
    private function assertPhoneNumberIdIsFree(Request $request, ?int $ignoreInstanceId = null): void
    {
        $owner = Instance::where('phone_number_id', $request->phone_number_id)
            ->where('active', true)
            ->when($ignoreInstanceId, fn ($q) => $q->where('id', '!=', $ignoreInstanceId))
            ->first();

        if ($owner) {
            throw ValidationException::withMessages([
                'phone_number_id' => 'Ese Phone Number ID ya está activo en otra instancia (#'.$owner->id
                    .'). Desactívala primero: si dos instancias comparten el número, los mensajes entrantes'
                    .' solo llegan a una de ellas.',
            ]);
        }
    }

    /**
     * Por qué el plan no deja encender una línea más de este canal, o null.
     *
     * Se devuelve como aviso (`flash`) y no como error de validación: la
     * pantalla pinta los avisos, y el mensaje dice qué hacer —desconectar la
     * actual o subir de plan—, que es lo que el cliente necesita leer.
     */
    private function sinCupo(?Company $company, string $canal, ?int $excepto = null): ?string
    {
        return $company ? PlanDeLaEmpresa::de($company)->motivoParaNoConectar($canal, $excepto) : null;
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone_number_id' => 'required|string',
            'waba_id' => 'required|string',
            'display_phone_number' => 'nullable|string',
            'access_token' => 'nullable|string',
        ]);

        $this->assertPhoneNumberIdIsFree($request);

        $user = auth()->user();

        if ($motivo = $this->sinCupo($user->company, Instance::CANAL_WHATSAPP)) {
            return back()->with('error', $motivo);
        }

        $instance = Instance::create([
            'company_id' => $user->company_id,
            'uuid' => Str::uuid(),
            'name' => $request->name,
            'phone_number_id' => $request->phone_number_id,
            'waba_id' => $request->waba_id,
            'display_phone_number' => $request->display_phone_number,
            'access_token' => $request->access_token,
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
        ]);

        // Y se da de alta en Integra, si la empresa lo tiene conectado. Sin
        // esto había que registrar la línea a mano en los dos sistemas, que es
        // de donde salían las líneas fantasma.
        $registro = app(RegistrarLineaEnIntegra::class)($instance);

        $respuesta = redirect()->route('instances.index')
            ->with('success', 'Instancia creada exitosamente');

        // El aviso sólo aparece cuando hay algo que el admin pueda arreglar:
        // que no tenga Integra conectado no es un problema que reportar.
        return isset($registro['aviso'])
            ? $respuesta->with('warning', $registro['aviso'])
            : $respuesta;
    }

    public function update(Request $request, $id)
    {
        $user = auth()->user();

        $instance = Instance::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $request->validate([
            'name' => 'required|string|max:255',
            'phone_number_id' => 'required|string',
            'waba_id' => 'required|string',
            'display_phone_number' => 'nullable|string',
            'access_token' => 'nullable|string',
            'active' => 'boolean',
        ]);

        if ($request->boolean('active', true)) {
            $this->assertPhoneNumberIdIsFree($request, $instance->id);

            // Reactivar una línea apagada es conectar una más: si no, bastaría
            // desactivar la vieja, conectar la nueva y volver a encender la
            // vieja para tener dos en el Básico.
            if (! $instance->active
                && ($motivo = $this->sinCupo($user->company, $instance->channel ?? Instance::CANAL_WHATSAPP, $instance->id))) {
                return back()->with('error', $motivo);
            }
        }

        $cambios = [
            'name' => $request->name,
            'phone_number_id' => $request->phone_number_id,
            'waba_id' => $request->waba_id,
            'display_phone_number' => $request->display_phone_number,
            'active' => $request->has('active') ? $request->active : 0,
        ];

        // El token sólo se toca si mandan uno nuevo. El formulario ya no lo
        // trae relleno —dejó de viajar al navegador el 11-sep-2026, porque con
        // él se envían mensajes como el cliente—, así que llega vacío cada vez
        // que alguien edita el nombre. Sin esta guarda, renombrar una línea la
        // dejaba sin token y muda.
        if (filled($request->access_token)) {
            $cambios['access_token'] = $request->access_token;
        }

        $instance->update($cambios);

        return redirect()->route('instances.index')
            ->with('success', 'Instancia actualizada exitosamente');
    }

    /**
     * Qué se perdería al borrar la instancia.
     *
     * Se pide al abrir el diálogo, no en cada visita a la pantalla: contar los
     * mensajes de todas las instancias de la empresa para pintar una lista que
     * casi nadie mira es pagar un barrido de `whatsapp_messages` por gusto.
     */
    public function resumenBorrado(Instance $instance)
    {
        $this->autorizarInstancia($instance);

        return response()->json($this->contarLoQueSePierde($instance));
    }

    /**
     * Apagar la instancia sin borrar nada.
     *
     * Es lo que casi siempre se quiere cuando alguien va a la papelera: dejar
     * de usar el número, no destruir el historial del cliente. La instancia
     * deja de recibir (el webhook sólo mira las activas) y de enviar, y todo
     * queda donde está para poder volver.
     */
    public function desconectar(Instance $instance)
    {
        $this->autorizarInstancia($instance);

        $instance->update(['active' => false]);

        return back()->with('success', 'Instancia desconectada. Sus conversaciones y mensajes siguen guardados.');
    }

    /**
     * Y volver a encenderla.
     *
     * Pasa por la misma comprobación que un alta: si otra instancia activa ya
     * tiene ese `phone_number_id`, encender esta dejaría dos peleándose por los
     * mismos mensajes entrantes y la segunda no recibiría ninguno.
     */
    public function reconectar(Request $request, Instance $instance)
    {
        $this->autorizarInstancia($instance);

        $request->merge([
            'phone_number_id' => $instance->phone_number_id,
            'company_id' => $instance->company_id,
        ]);

        $this->assertPhoneNumberIdIsFree($request, $instance->id);

        // Reconectar una línea apagada es encender una más: pasa por el cupo
        // del plan igual que conectar una nueva.
        if (! $instance->active
            && ($motivo = $this->sinCupo($instance->company, $instance->channel ?? Instance::CANAL_WHATSAPP, $instance->id))) {
            return back()->with('error', $motivo);
        }

        $instance->update(['active' => true]);

        return back()->with('success', 'Instancia reconectada.');
    }

    /**
     * El borrado de verdad, que es irreversible.
     *
     * Exige escribir el nombre de la instancia. No es burocracia: no hay
     * `SoftDeletes` en el proyecto y las claves foráneas están en cascada, así
     * que este botón se lleva por delante las conversaciones y todos sus
     * mensajes. El 8-sep-2026 se llevó 11 chats y 53 mensajes de un número
     * recién sincronizado por coexistencia, con un `confirm()` del navegador
     * como única defensa, y el historial de coexistencia **no se puede volver a
     * importar**: Meta sólo permite una sincronización por número, así que
     * recuperarlo obliga a desconectar el número desde la app del cliente y
     * rehacer el registro entero.
     *
     * Borrar una sola conversación pasa por `ConversationDeletionRequest` y su
     * aprobación; esto se lleva todas a la vez. Que al menos haya que leer.
     */
    public function destroy(Request $request, $id)
    {
        $user = auth()->user();

        $instance = Instance::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $confirmacion = trim((string) $request->input('confirmacion'));

        if (mb_strtolower($confirmacion) !== mb_strtolower(trim((string) $instance->name))) {
            throw ValidationException::withMessages([
                'confirmacion' => 'Escribe el nombre de la instancia tal como aparece para confirmar que quieres borrarla.',
            ]);
        }

        // Se deja constancia de lo que había antes de que desaparezca: es la
        // única forma de responder a "¿cuántos mensajes teníamos?" cuando
        // alguien pregunte mañana.
        $perdido = $this->contarLoQueSePierde($instance);

        Log::channel('whatsapp')->warning('🗑️ Instancia eliminada con sus conversaciones', [
            'instance_id' => $instance->id,
            'company_id' => $instance->company_id,
            'phone_number_id' => $instance->phone_number_id,
            'usuario' => $user->email,
        ] + $perdido);

        // Una página de Messenger se suscribió al webhook al conectarla, y borrar
        // la fila no le dice nada a Meta: la página seguía mandándonos sus
        // mensajes. El 27-sep-2026, preparando el App Review, se conectó por
        // error la página de un cliente real a la empresa de pruebas; borrarla
        // la dejaba enviando. Si Meta no contesta se borra igual —dejar una
        // página sin poder quitarse sería peor—, pero queda escrito.
        if ($instance->esMessenger()) {
            try {
                $desuscrita = app(MessengerLoginService::class)->desuscribirPagina($instance);
            } catch (\Throwable $e) {
                $desuscrita = false;
            }

            if (! $desuscrita) {
                Log::channel('messenger')->warning('⚠️ La página borrada sigue suscrita en Meta', [
                    'instance_id' => $instance->id,
                    'pagina' => $instance->external_account_id,
                ]);
            }
        }

        $instance->delete();

        return redirect()->route('instances.index')
            ->with('success', "Instancia eliminada, junto con {$perdido['conversaciones']} conversaciones y {$perdido['mensajes']} mensajes.");
    }

    private function autorizarInstancia(Instance $instance): void
    {
        abort_unless($instance->company_id === auth()->user()->company_id, 403);
    }

    /**
     * Los contactos van aparte a propósito: cuelgan de la empresa, no de la
     * instancia, así que sobreviven al borrado. Se devuelven para poder decirlo
     * en el diálogo en vez de dejarlo a la sorpresa.
     */
    private function contarLoQueSePierde(Instance $instance): array
    {
        $conversaciones = WhatsAppConversation::where('instance_id', $instance->id);

        return [
            'nombre' => $instance->name,
            'conversaciones' => $conversaciones->count(),
            'mensajes' => WhatsAppMessage::whereIn(
                'conversation_id',
                WhatsAppConversation::where('instance_id', $instance->id)->select('id')
            )->count(),
            'campanas' => WhatsAppCampaign::where('instance_id', $instance->id)->count(),
            'contactos_empresa' => Contact::where('company_id', $instance->company_id)->count(),
            'historial_importado' => $instance->coexistenceSync !== null,
        ];
    }

    /**
     * Crea la credencial de la API v1 y la devuelve una sola vez.
     *
     * Hasta ahora el token era el `phone_number_id`, que se enseña en esta misma
     * pantalla y en el panel de Meta: quien lo viera podía leer los mensajes de
     * la empresa y enviar en su nombre.
     *
     * Va por JSON y no por redirección con flash porque el token no debe quedar
     * guardado en la sesión: de ahí acabaría en el almacenamiento del navegador
     * y en los logs del servidor.
     */
    public function generateApiToken($id)
    {
        $user = auth()->user();

        $instance = Instance::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $yaTenia = $instance->tieneApiToken();
        $token = $instance->generarApiToken();

        Log::channel('whatsapp')->info('Token de API generado para una instancia', [
            'instance_id' => $instance->id,
            'company_id' => $instance->company_id,
            'por_usuario' => $user->id,
            'reemplaza_uno_anterior' => $yaTenia,
        ]);

        return response()->json([
            'token' => $token,
            'reemplaza_uno_anterior' => $yaTenia,
            // Se dice también aquí y no sólo en la pantalla: quien llame a esta
            // ruta desde un script tiene que saber que no hay segunda copia.
            'aviso' => 'Guárdalo ahora. No se puede volver a ver: si se pierde, hay que generar otro.',
        ]);
    }
}
