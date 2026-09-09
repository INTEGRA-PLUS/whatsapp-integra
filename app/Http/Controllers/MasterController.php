<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

        // Los cuatro bloques del dashboard van como closures a propósito.
        //
        // Buscar una empresa es una visita Inertia a esta misma acción, así que
        // antes cada tecla recalculaba el dashboard entero —dos barridos
        // completos de whatsapp_messages y una subconsulta correlacionada por
        // empresa— para devolver diez filas de `companies`. Como closures,
        // Inertia sólo los evalúa cuando la petición los pide: el buscador hace
        // una recarga parcial de `companies` y esto ni se toca.
        //
        // Si algún día se convierten en valores ya calculados, la lentitud
        // vuelve sin que nada falle: cuidado ahí.
        $stats = fn () => [
            'total_companies' => Company::count(),
            'active_companies' => Company::where('active', true)->count(),
            'total_instances' => Instance::count(),
            'active_instances' => Instance::where('status', 'active')->count(),
            'total_messages_range' => WhatsAppMessage::whereBetween('created_at', [$startDate, $endDate])->count(),
            'total_users' => User::count(),
        ];

        // Growth Chart: New Companies
        $companies_growth = fn () => Company::select(
            DB::raw("DATE_FORMAT(created_at, '$dateFormat') as date"),
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Message Volume: Inbound vs Outbound
        $messages_volume = fn () => WhatsAppMessage::select(
            DB::raw("DATE_FORMAT(created_at, '$dateFormat') as date"),
            DB::raw('SUM(CASE WHEN direction = "inbound" THEN 1 ELSE 0 END) as inbound'),
            DB::raw('SUM(CASE WHEN direction = "outbound" THEN 1 ELSE 0 END) as outbound')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top Companies by activity.
        //
        // El conteo de mensajes se agrega UNA vez para todas las empresas y se
        // pega con un join. Antes era una subconsulta correlacionada en el
        // SELECT —mensajes → conversaciones → instancias, con dos whereIn
        // anidados— y, como el orden depende de ella, MySQL la resolvía para
        // cada empresa de la tabla, no para las seis que se devuelven.
        $top_companies = function () {
            $messagesPerCompany = DB::table('whatsapp_messages as wm')
                ->join('whatsapp_conversations as c', 'c.id', '=', 'wm.conversation_id')
                ->join('instances as i', 'i.id', '=', 'c.instance_id')
                ->selectRaw('i.company_id, count(*) as total')
                ->groupBy('i.company_id');

            return Company::query()
                ->select('companies.*')
                ->addSelect(DB::raw('COALESCE(m.total, 0) as messages_count'))
                ->leftJoinSub($messagesPerCompany, 'm', 'm.company_id', '=', 'companies.id')
                ->withCount(['instances', 'users'])
                ->orderByDesc('messages_count')
                ->take(6)
                ->get();
        };

        $query = Company::with(['users' => function ($query) {
            $query->where('role', 'admin');
        }])->withCount(['users', 'instances']);

        // Search Scope
        if ($request->has('search') && $request->search != '') {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('email', 'like', "%{$searchTerm}%")
                    ->orWhereHas('users', function ($q) use ($searchTerm) {
                        $q->where('role', 'admin')
                            ->where(function ($subQ) use ($searchTerm) {
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
            // Los usuarios de una empresa concreta, para el modal que permite
            // restablecerles la contraseña. Va en un closure y se pide con una
            // recarga parcial (`?users_of=<id>`) porque cargar los usuarios de
            // las diez empresas de la página en cada visita es pagar una
            // consulta que casi nunca se mira.
            'company_users' => fn () => $this->usuariosDeEmpresa($request),
            'filters' => [
                'search' => $request->search,
                'status' => $request->status,
                'range' => $range,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
        ]);
    }

    /**
     * Los usuarios de la empresa que pida el panel, o nada.
     *
     * Devolver `[]` cuando no se pide es lo que permite dejar la prop en el
     * render de siempre sin encarecer la carga completa.
     */
    private function usuariosDeEmpresa(Request $request): array
    {
        $companyId = $request->integer('users_of');

        if (! $companyId) {
            return [];
        }

        return User::where('company_id', $companyId)
            // 'admin' < 'agent' < 'user' también alfabéticamente, así que el
            // orden que queremos sale sin un FIELD() de MySQL que dejaría esto
            // sin poder probarse en sqlite.
            ->orderBy('role')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'active'])
            ->toArray();
    }

    /**
     * Le pone una contraseña temporal a cualquier usuario de cualquier empresa.
     *
     * El master ya podía cambiar la del admin de una empresa desde el modal de
     * edición, pero no la de un agente: para esos, soporte tenía que pedirle al
     * admin del cliente que lo hiciera, y si el que se había quedado fuera era
     * justo el admin, no había a quién pedírselo.
     *
     * La contraseña se genera aquí y se devuelve una sola vez por flash, en vez
     * de dejar que la escriba quien atiende: una tecleada a mano por teléfono
     * acaba siendo "12345678", y encima queda escrita en el chat de soporte.
     */
    public function resetUserPassword(User $user)
    {
        $this->authorizeMaster();

        $temporal = Str::password(12, symbols: false);

        $user->update(['password' => $temporal]);

        // Queda registrado: es un cambio de credenciales de otra persona, y el
        // día que alguien pregunte "¿quién me cambió la contraseña?" la
        // respuesta tiene que estar en algún sitio.
        Log::warning('El master restableció la contraseña de un usuario', [
            'master_id' => Auth::id(),
            'user_id' => $user->id,
            'user_email' => $user->email,
            'company_id' => $user->company_id,
        ]);

        return back()->with([
            'temp_password' => $temporal,
            'temp_password_for' => $user->email,
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

        // Todo el alta en una transacción: una empresa sin administrador, o con
        // el rol a medio permisar, no se puede arreglar desde el panel —hay que
        // entrar a la base de datos—, y encima deja el nombre y el email
        // ocupados, así que el reintento choca contra su propio primer intento.
        DB::transaction(function () use ($request) {
            $company = Company::create([
                'name' => $request->name,
                'slug' => Company::slugUnico($request->name),
                'email' => $request->email,
                'active' => true,
            ]);

            // Explicitly set team ID for spatie permissions
            setPermissionsTeamId($company->id);

            $adminRole = Role::firstOrCreate([
                'name' => 'admin',
                'company_id' => $company->id,
                'guard_name' => 'web',
            ]);

            // El admin de una empresa siempre debe tener TODOS los permisos disponibles,
            // incluyendo cualquier permiso nuevo agregado por migraciones futuras.
            $adminRole->syncPermissions(Permission::all());

            $adminUser = User::create([
                'company_id' => $company->id,
                'name' => $request->admin_name,
                'email' => $request->admin_email,
                'password' => bcrypt($request->password),
                'role' => 'admin',
                'active' => true,
            ]);

            if (! $adminUser->hasRole('admin')) {
                $adminUser->assignRole($adminRole);
            }
        });

        return redirect()->route('master.index')->with('success', 'Empresa creada exitosamente con administrador configurado.');
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeMaster();

        $adminUser = $company->users()->where('role', 'admin')->where('active', true)->first();
        $adminUserId = $adminUser ? $adminUser->id : null;

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:companies,email,'.$company->id,
            'active' => 'boolean',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email,'.$adminUserId,
            'password' => 'nullable|string|min:8',
        ]);

        $company->update([
            'name' => $request->name,
            'slug' => Company::slugUnico($request->name, $company->id),
            'email' => $request->email,
            'active' => $request->active ?? false,
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
            $adminRole = Role::firstOrCreate([
                'name' => 'admin',
                'company_id' => $company->id,
                'guard_name' => 'web',
            ]);

            // Re-sync permissions just in case new ones were added
            $adminRole->syncPermissions(Permission::all());

            if (! $adminUser->hasRole('admin')) {
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

        if (! $userToImpersonate) {
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
        if (! session()->has('impersonated_by')) {
            return redirect()->route('chat.index');
        }

        $originalUserId = session()->pull('impersonated_by');
        session()->forget('from_master');
        session()->forget('company_name');

        $originalUser = User::find($originalUserId);

        if ($originalUser) {
            // El rol "master" está scoped a la empresa del usuario master.
            // Forzamos el team scope antes de validar para que hasRole('master')
            // resuelva correctamente y no se cierre la sesión.
            setPermissionsTeamId($originalUser->company_id);

            if ($originalUser->isMaster()) {
                Auth::login($originalUser);
                session()->put('company_id', $originalUser->company_id);

                return redirect()->route('master.index')->with('success', 'Bienvenido de vuelta, Master.');
            }
        }

        session()->forget('company_id');
        Auth::logout();

        return redirect()->route('login');
    }

    private function authorizeMaster()
    {
        if (! Auth::user() || ! Auth::user()->isMaster()) {
            abort(403, 'Acceso denegado. Solo usuarios Master.');
        }
    }
}
