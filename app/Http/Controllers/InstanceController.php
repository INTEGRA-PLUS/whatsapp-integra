<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\Instance;
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

        if ($user->isMaster() && !session('impersonated_by')) {
            return redirect()->route('master.index');
        }

        $instances = Instance::where('company_id', $user->company_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Instances/Index', [
            'instances' => $instances,
        ]);
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
                'phone_number_id' => 'Ese Phone Number ID ya está activo en otra instancia (#' . $owner->id
                    . '). Desactívala primero: si dos instancias comparten el número, los mensajes entrantes'
                    . ' solo llegan a una de ellas.',
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
            'access_token' => 'nullable|string'
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
            'active' => true
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
            'active' => 'boolean'
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
            'active' => $request->has('active') ? $request->active : 0
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
}
