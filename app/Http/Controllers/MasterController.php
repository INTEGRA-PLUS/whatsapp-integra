<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\User;
use App\Models\Instance;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Inertia\Inertia;

class MasterController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeMaster();

        // Date Range Logic
        $range = $request->input('range', 'month');
        $endDate = Carbon::now()->endOfDay();
        $startDate = Carbon::now()->subDays(30)->startOfDay();

        if ($range === 'week') {
            $startDate = Carbon::now()->subDays(7)->startOfDay();
        } elseif ($range === 'year') {
            $startDate = Carbon::now()->subYear()->startOfDay();
        } elseif ($range === 'custom') {
            if ($request->filled('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
            }
            if ($request->filled('end_date')) {
                $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
            }
        }

        $daysDiff = $startDate->diffInDays($endDate);
        
        // Define grouping based on range duration
        $dateFormat = '%Y-%m-%d';
        if ($daysDiff > 90 && $daysDiff <= 365) {
            $dateFormat = '%Y-%u'; // Weekly
        } elseif ($daysDiff > 365) {
            $dateFormat = '%Y-%m'; // Monthly
        }

        // Basic Stats
        $stats = [
            'total_companies' => Company::count(),
            'active_companies' => Company::where('active', true)->count(),
            'total_instances' => Instance::count(),
            'active_instances' => Instance::where('status', 'active')->count(),
            'total_messages_range' => WhatsAppMessage::whereBetween('created_at', [$startDate, $endDate])->count(),
            'total_users' => User::count(),
        ];

        // Growth Chart: New Companies
        $companies_growth = Company::select(
                DB::raw("DATE_FORMAT(created_at, '$dateFormat') as date"), 
                DB::raw('count(*) as count')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Message Volume: Inbound vs Outbound
        $messages_volume = WhatsAppMessage::select(
                DB::raw("DATE_FORMAT(created_at, '$dateFormat') as date"),
                DB::raw('SUM(CASE WHEN direction = "inbound" THEN 1 ELSE 0 END) as inbound'),
                DB::raw('SUM(CASE WHEN direction = "outbound" THEN 1 ELSE 0 END) as outbound')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top Companies by activity
        $top_companies = Company::withCount(['instances', 'users'])
            ->addSelect(['messages_count' => WhatsAppMessage::selectRaw('count(*)')
                ->whereIn('conversation_id', function($query) {
                    $query->select('id')->from('whatsapp_conversations')->whereIn('instance_id', function($q) {
                        $q->select('id')->from('instances')->whereColumn('company_id', 'companies.id');
                    });
                })
            ])
            ->orderBy('messages_count', 'desc')
            ->take(6)
            ->get();

        $query = Company::with(['users' => function($query) {
            $query->where('role', 'admin');
        }])->withCount(['users', 'instances']);

        // Search Scope
        if ($request->has('search') && $request->search != '') {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                  ->orWhere('email', 'like', "%{$searchTerm}%")
                  ->orWhereHas('users', function($q) use ($searchTerm) {
                      $q->where('role', 'admin')
                        ->where(function($subQ) use ($searchTerm) {
                            $subQ->where('name', 'like', "%{$searchTerm}%")
                                 ->orWhere('email', 'like', "%{$searchTerm}%");
                        });
                  });
            });
        }

        // Status Filter
        if ($request->has('status') && $request->status !== null) {
            if ($request->status == 'active') {
                $query->where('active', true);
            } elseif ($request->status == 'inactive') {
                $query->where('active', false);
            }
        }

        $companies = $query->orderBy('created_at', 'desc')->paginate(10);

        return Inertia::render('Master/Index', [
            'stats' => $stats,
            'companies_growth' => $companies_growth,
            'messages_volume' => $messages_volume,
            'top_companies' => $top_companies,
            'companies' => $companies,
            'filters'   => [
                'search' => $request->search,
                'status' => $request->status,
                'range' => $range,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
        ]);
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

        // Explicitly set team ID for spatie permissions
        setPermissionsTeamId($company->id);

        User::create([
            'company_id' => $company->id,
            'name' => $request->admin_name,
            'email' => $request->admin_email,
            'password' => bcrypt($request->password),
            'role' => 'admin',
            'active' => true,
        ]);

        return redirect()->route('master.index')->with('success', 'Empresa creada exitosamente con administrador configurado.');
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
                'role' => 'admin', // Ensure role column is set
            ];

            if ($request->filled('password')) {
                $userData['password'] = bcrypt($request->password);
            }

            $adminUser->update($userData);

            // Ensure they have the admin role in spatie permissions too
            setPermissionsTeamId($company->id);
            $adminRole = \Spatie\Permission\Models\Role::firstOrCreate([
                'name' => 'admin',
                'company_id' => $company->id,
                'guard_name' => 'web'
            ]);
            
            // Re-sync permissions just in case new ones were added
            $adminRole->syncPermissions(\Spatie\Permission\Models\Permission::all());
            
            if (!$adminUser->hasRole('admin')) {
                $adminUser->assignRole($adminRole);
            }
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
        session()->put('from_master', true);
        session()->put('company_id', $userToImpersonate->company_id);
        session()->put('company_name', $userToImpersonate->company->name);
        
        Auth::login($userToImpersonate);

        return redirect()->route('chat.index')->with('success', "Ahora estás actuando como {$userToImpersonate->name} en {$userToImpersonate->company->name}");
    }

    public function stopImpersonating()
    {
        if (!session()->has('impersonated_by')) {
            return redirect()->route('chat.index');
        }

        $originalUserId = session()->pull('impersonated_by');
        session()->forget('from_master');
        session()->forget('company_id');
        session()->forget('company_name');
        
        $originalUser = User::find($originalUserId);

        if ($originalUser && $originalUser->isMaster()) {
            Auth::login($originalUser);
            return redirect()->route('master.index')->with('success', 'Bienvenido de vuelta, Master.');
        }

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
