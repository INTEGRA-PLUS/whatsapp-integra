<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Instance;

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

        return view('instances.index', compact('instances'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone_number_id' => 'required|string',
            'waba_id' => 'required|string',
            'display_phone_number' => 'nullable|string'
        ]);

        $user = auth()->user();

        $instance = Instance::create([
            'company_id' => $user->company_id,
            'uuid' => Str::uuid(),
            'name' => $request->name,
            'phone_number_id' => $request->phone_number_id,
            'waba_id' => $request->waba_id,
            'display_phone_number' => $request->display_phone_number,
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
            'active' => 'boolean'
        ]);

        $instance->update([
            'name' => $request->name,
            'phone_number_id' => $request->phone_number_id,
            'waba_id' => $request->waba_id,
            'display_phone_number' => $request->display_phone_number,
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
