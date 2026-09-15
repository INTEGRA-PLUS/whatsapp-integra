<?php

namespace App\Http\Controllers;

use App\Extensions\Extension;
use App\Extensions\ExtensionRegistry;
use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Carbon\Carbon;
use App\Support\ContadorDeIa;
use App\Support\PlanDeLaEmpresa;
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
            // Sólo lo que entró o salió de verdad. `whatsapp_messages` guarda
            // también los avisos de sistema del hilo —«conversación reabierta»,
            // «cerrada»— con `direction = 'internal'`: nunca viajaron a Meta y
            // el cliente no los ve. Contándolos, esta tarjeta decía 327.795
            // mientras el gráfico de justo debajo sumaba 313.837, porque la
            // serie sí separa por dirección. Dos totales del mismo dato en la
            // misma pantalla, y el bueno era el de abajo.
            'total_messages_range' => WhatsAppMessage::whereBetween('created_at', [$startDate, $endDate])
                ->whereIn('direction', ['inbound', 'outbound'])
                ->count(),
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
        $top_companies = function () use ($startDate, $endDate) {
            // Se cuenta por conversación PRIMERO y se sube a empresa después,
            // y no de un tirón con los dos joins, porque el plan que elegía
            // MySQL con la consulta directa era el del revés: recorría las 52
            // instancias, de cada una sus ~1.163 conversaciones y de cada
            // conversación sus mensajes. Sesenta mil búsquedas por índice, una
            // por conversación —6,2 s—, en vez de un solo barrido del rango de
            // fechas —2,8 s—. Medido en producción el 15-sep-2026.
            //
            // El rango, además de ser lo que la pantalla promete, es lo que hace
            // viable la consulta: sin él, filtrar por dirección la dejaba en 69
            // segundos y tumbaba el panel entero. Antes sumaba TODA la historia
            // de cada empresa mientras la pantalla decía «mensajes en el rango
            // elegido», así que mover el selector de fechas no cambiaba el
            // ranking ni un número: NovaLink salía con 115.344 mensajes cuando
            // en el último mes llevaba 15.858.
            $porConversacion = DB::table('whatsapp_messages')
                ->selectRaw('conversation_id, count(*) as c')
                ->whereBetween('created_at', [$startDate, $endDate])
                // Mismo criterio que la tarjeta y el gráfico: los avisos de
                // sistema del hilo no son actividad de la empresa.
                ->whereIn('direction', ['inbound', 'outbound'])
                ->groupBy('conversation_id');

            $messagesPerCompany = DB::query()
                ->fromSub($porConversacion, 't')
                ->join('whatsapp_conversations as c', 'c.id', '=', 't.conversation_id')
                ->join('instances as i', 'i.id', '=', 'c.instance_id')
                ->selectRaw('i.company_id, sum(t.c) as total')
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

        // withQueryString() y no paginate() a secas: sin él los enlaces de
        // página salen limpios y pasar a la segunda página pierde la búsqueda
        // y el filtro de estado que se acababan de aplicar.
        $companies = $query->orderBy('created_at', 'desc')->paginate(10)->withQueryString();

        // El plan y el consumo de IA de cada empresa de la página. Se resuelve
        // aquí y no en el modelo porque depende de configuración, y se hace
        // sobre las diez de la página —no sobre todas— para que el panel no
        // pague una consulta por empresa del sistema.
        $companies->getCollection()->transform(function (Company $company) {
            $company->setAttribute('plan_resumen', PlanDeLaEmpresa::de($company)->resumen());
            $company->setAttribute('uso_ia', ContadorDeIa::estado($company));

            return $company;
        });

        return Inertia::render('Master/Index', [
            'stats' => $stats,
            // El catálogo va al frontend para que los selectores del modal de
            // plan salgan de la misma fuente que el candado. Si algún día se
            // añade un plan, aparece solo.
            'planes' => collect(config('planes.disponibles'))
                ->map(fn (array $p, string $slug) => [
                    'value' => $slug,
                    'label' => $p['nombre'],
                    'con_ia' => ($p['ia'] ?? null) !== null,
                ])->values(),
            'cobros' => config('planes.cobros'),
            // Va en closure por lo mismo que los bloques del dashboard: teclear
            // en el buscador de empresas es una visita a esta acción, y esto
            // recorre todas las empresas del sistema.
            'planes_resumen' => fn () => $this->resumenDePlanes(),
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
     * La escalera de precios, con cuántas empresas caen hoy en cada tramo.
     *
     * El tramo se mide en contactos: cada persona distinta que le ha escrito a
     * la empresa por WhatsApp, que es lo que `contacts` va guardando sola. En
     * una cooperativa o un ISP a esas personas las llaman socios o suscriptores,
     * y de ahí sale la palabra en la propuesta comercial — pero lo que el
     * sistema cuenta son contactos, y conviene que la pantalla lo diga con esa
     * palabra para que nadie tenga que adivinar qué se le está cobrando.
     *
     * El reparto sale de UNA consulta agrupada y no de `contactosReales()` por
     * empresa, que serían cincuenta y cuatro.
     *
     * @return list<array<string, mixed>>
     */
    private function escaleraDePrecios(): array
    {
        $contactosPorEmpresa = DB::table('contacts')
            ->selectRaw('company_id, count(*) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        // Las empresas sin un solo contacto también ocupan un tramo —el
        // primero—: son clientes recién conectados, no clientes que no existen.
        $totales = Company::pluck('id')
            ->map(fn (int $id) => (int) ($contactosPorEmpresa[$id] ?? 0));

        $topes = array_keys(config('planes.precios', []));
        $anterior = 0;
        $escalera = [];

        foreach ($topes as $hasta) {
            $escalera[] = [
                'hasta' => (int) $hasta,
                'ia' => (int) config("planes.credito_ia.{$hasta}", 0),
                'precios' => collect(config('planes.disponibles'))
                    ->map(fn (array $plan, string $slug) => config("planes.precios.{$hasta}.{$slug}"))
                    ->all(),
                'empresas' => $totales
                    ->filter(fn (int $t) => ($t > $anterior && $t <= $hasta) || ($anterior === 0 && $t === 0))
                    ->count(),
            ];

            $anterior = (int) $hasta;
        }

        return $escalera;
    }

    /**
     * El catálogo de planes con lo que de verdad hay detrás de cada uno.
     *
     * La pestaña de planes del panel llevaba tres tarjetas y una tabla escritas
     * a pelo en el JSX —"Startup 49,99 USD, 12 suscripciones"— que no salían de
     * ninguna parte: ni los planes eran los del producto (Esencial,
     * Automatización, Inteligente) ni esas cifras se habían cobrado nunca.
     *
     * Las empresas se normalizan con `PlanDeLaEmpresa` y no con un `group by`
     * de la columna: una empresa con `plan` a null cuenta como Inteligente
     * —que es lo que el candado de las extensiones le aplica—, y agrupando por
     * la columna a pelo se quedaría fuera de todos los planes y los números no
     * sumarían el total de empresas.
     *
     * @return array<string, mixed>
     */
    private function resumenDePlanes(): array
    {
        $nombresDeExtension = collect(app(ExtensionRegistry::class)->all())
            ->mapWithKeys(fn (Extension $extension) => [$extension->slug() => $extension->name()]);

        $empresas = Company::query()
            ->get(['id', 'plan', 'cobro', 'gratis_hasta', 'contactos_contratados'])
            ->map(fn (Company $company) => PlanDeLaEmpresa::de($company));

        return [
            'planes' => collect(config('planes.disponibles'))
                ->map(function (array $plan, string $slug) use ($empresas, $nombresDeExtension) {
                    $suyas = $empresas->filter(fn (PlanDeLaEmpresa $p) => $p->slug() === $slug);
                    $todas = ($plan['extensiones'] ?? []) === '*';

                    return [
                        'slug' => $slug,
                        'nombre' => $plan['nombre'],
                        // Un plan no tiene «un» precio: tiene uno por tramo.
                        // Se manda el rango, que es lo que se puede decir sin
                        // mentir — «Inteligente va de 65 a 419 según el tamaño».
                        'precio_desde' => collect(config('planes.precios'))->min(fn (array $f) => $f[$slug] ?? null),
                        'precio_hasta' => collect(config('planes.precios'))->max(fn (array $f) => $f[$slug] ?? null),
                        'ia' => $plan['ia'],
                        'todas_las_extensiones' => $todas,
                        'extensiones' => $todas
                            ? $nombresDeExtension->values()->all()
                            : collect($plan['extensiones'])
                                ->map(fn (string $s) => $nombresDeExtension[$s] ?? $s)
                                ->all(),
                        'empresas' => $suyas->count(),
                        'facturando' => $suyas->filter(fn (PlanDeLaEmpresa $p) => $p->seFactura())->count(),
                    ];
                })
                ->values(),

            // La escalera entera, tramo por tramo y plan por plan. La pantalla
            // enseñaba de cada plan sólo su precio menor y su mayor —«35 a
            // 259»— y eso se lee como un precio negociable o un «depende»,
            // cuando son quince precios fijos: cinco tramos por tres planes. La
            // pregunta que se hace delante de un cliente es «¿cuánto le cobro a
            // uno de 5.000 socios?», y esa se responde con la tabla, no con el
            // rango.
            //
            // El crédito de IA va en la misma fila porque es el mismo tramo: son
            // dos columnas de una misma escalera, y separarlas obligaba a
            // cruzarlas de cabeza.
            'tramos' => $this->escaleraDePrecios(),

            // Dos meses gratis pagando el año: es parte del precio, no una
            // promoción, y es lo que ancla los 250 USD de Cootramed.
            'meses_gratis_al_pagar_anual' => (int) config('planes.meses_gratis_al_pagar_anual', 0),

            'cobros' => collect(config('planes.cobros', []))
                ->mapWithKeys(fn (string $cobro) => [
                    $cobro => $empresas->filter(fn (PlanDeLaEmpresa $p) => $p->cobro() === $cobro)->count(),
                ]),

            'total_empresas' => $empresas->count(),
        ];
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

    /**
     * Cambia el plan y el cobro de una empresa.
     *
     * Separado de `update()` a propósito: ese formulario lo usa soporte para
     * corregir el nombre o el correo del admin, y meter aquí el plan haría que
     * cualquier corrección tipográfica arrastrara una decisión comercial.
     *
     * Es el único sitio donde se conceden meses gratis y cortesías, y por eso
     * exige una nota: dentro de un año nadie va a recordar por qué esta empresa
     * no paga, y sin un sitio donde escribirlo acaba en un WhatsApp.
     */
    public function updatePlan(Request $request, Company $company)
    {
        $this->authorizeMaster();

        $datos = $request->validate([
            'plan' => 'required|string|in:'.implode(',', array_keys(config('planes.disponibles'))),
            'cobro' => 'required|string|in:'.implode(',', config('planes.cobros')),
            'contactos_contratados' => 'nullable|integer|min:0|max:1000000',
            'gratis_hasta' => 'nullable|date',
            'nota_de_cobro' => 'nullable|string|max:300',
        ]);

        $company->update($datos);

        Log::channel('whatsapp')->info('💳 Plan de empresa cambiado', [
            'empresa' => $company->id,
            'plan' => $datos['plan'],
            'cobro' => $datos['cobro'],
            'por' => auth()->id(),
        ]);

        return back()->with('success', 'Plan actualizado.');
    }

    /**
     * Un mes más de gracia, a partir de hoy o de donde acabara el anterior.
     *
     * Atajo de un clic porque es lo que se hace de verdad en una llamada:
     * «dame un mes más». Se suma al que hubiera en vez de reemplazarlo — dos
     * clics son dos meses, que es lo que espera quien los da.
     */
    public function mesGratis(Company $company)
    {
        $this->authorizeMaster();

        $desde = $company->gratis_hasta && $company->gratis_hasta->isFuture()
            ? $company->gratis_hasta
            : now();

        $company->update(['gratis_hasta' => $desde->copy()->addMonth()]);

        return back()->with('success', 'Un mes gratis más, hasta el '.$company->gratis_hasta->format('d/m/Y').'.');
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
