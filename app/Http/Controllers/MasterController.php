<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class MasterController extends Controller
{
    public function index()
    {
        $this->authorizeMaster();
        $companies = Company::with(['users' => function($query) {
            $query->where('role', 'admin')->where('active', true);
        }])->withCount(['users', 'instances'])->get();
        
        return view('master.index', compact('companies'));
    }

    public function store(Request $request)
    {
        $this->authorizeMaster();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:companies,email',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $company = Company::create([
            'name' => $request->name,
            'slug' => \Illuminate\Support\Str::slug($request->name),
            'email' => $request->email,
            'active' => true,
        ]);

        User::create([
            'company_id' => $company->id,
            'name' => $request->admin_name,
            'email' => $request->admin_email,
            'password' => bcrypt($request->password),
            'role' => 'admin',
            'active' => true,
        ]);

        return redirect()->route('master.index')->with('success', 'Empresa creada exitosamente.');
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeMaster();

        $adminUser = $company->users()->where('role', 'admin')->where('active', true)->first();
        $adminUserId = $adminUser ? $adminUser->id : null;

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:companies,email,' . $company->id,
            'active' => 'boolean',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email,' . $adminUserId,
            'password' => 'nullable|string|min:8',
        ]);

        $company->update([
            'name' => $request->name,
            'slug' => \Illuminate\Support\Str::slug($request->name),
            'email' => $request->email,
            'active' => $request->active ?? false
        ]);

        if ($adminUser) {
            $userData = [
                'name' => $request->admin_name,
                'email' => $request->admin_email,
            ];

            if ($request->filled('password')) {
                $userData['password'] = bcrypt($request->password);
            }

            $adminUser->update($userData);
        }

        return redirect()->route('master.index')->with('success', 'Empresa y administrador actualizados exitosamente.');
    }

    public function impersonate($companyId)
    {
        $this->authorizeMaster();

        $originalUserId = Auth::id();
        
        $userToImpersonate = User::where('company_id', $companyId)
            ->whereIn('role', ['admin', 'agent'])
            ->first();

        if (!$userToImpersonate) {
            return back()->with('error', 'No se encontró un usuario administrador o agente en esta empresa para suplantar.');
        }

        session()->put('impersonated_by', $originalUserId);
        Auth::login($userToImpersonate);

        return redirect()->route('chat.index')->with('success', "Ahora estás actuando como {$userToImpersonate->name} en {$userToImpersonate->company->name}");
    }

    public function stopImpersonating()
    {
        if (!session()->has('impersonated_by')) {
            return redirect()->route('chat.index');
        }

        $originalUserId = session()->pull('impersonated_by');
        $originalUser = User::find($originalUserId);

        if ($originalUser && $originalUser->isMaster()) {
            Auth::login($originalUser);
            return redirect()->route('master.index')->with('success', 'Bienvenido de vuelta, Master.');
        }

        // Fallback if original user is not found or not master
        Auth::logout();
        return redirect()->route('login');
    }

    private function authorizeMaster()
    {
        if (!Auth::user() || !Auth::user()->isMaster()) {
            abort(403, 'Acceso denegado. Solo usuarios Master.');
        }
    }
}
