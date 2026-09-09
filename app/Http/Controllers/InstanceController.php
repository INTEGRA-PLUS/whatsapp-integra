<?php

namespace App\Http\Controllers;

use App\Models\Instance;
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
        ]);
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

        return redirect()->route('instances.index')
            ->with('success', 'Instancia creada exitosamente');
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
        }

        $instance->update([
            'name' => $request->name,
            'phone_number_id' => $request->phone_number_id,
            'waba_id' => $request->waba_id,
            'display_phone_number' => $request->display_phone_number,
            'access_token' => $request->access_token,
            'active' => $request->has('active') ? $request->active : 0,
        ]);

        return redirect()->route('instances.index')
            ->with('success', 'Instancia actualizada exitosamente');
    }

    public function destroy($id)
    {
        $user = auth()->user();

        $instance = Instance::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $instance->delete();

        return redirect()->route('instances.index')
            ->with('success', 'Instancia eliminada');
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
