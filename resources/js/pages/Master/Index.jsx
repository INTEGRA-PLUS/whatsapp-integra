import { useState, useCallback, useMemo, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { 
    Plus, 
    Search, 
    Users, 
    Layers, 
    LogIn, 
    Pencil, 
    TrendingUp, 
    BarChart3, 
    Globe, 
    MessageSquare,
    Activity,
    CheckCircle2,
    Briefcase,
    ArrowUpRight,
    ArrowDownRight,
    Calendar,
    Filter,
    ChevronRight,
    SearchCheck,
    Package,
    BarChart as BarChartIcon,
    KeyRound,
    Copy,
    ShieldAlert,
    X as XIcon,
    UserCog,
    CreditCard,
    Download,
} from 'lucide-react';

export default function MasterIndex({ stats, companies_growth, messages_volume, top_companies, companies, company_users, filters, planes = [], cobros = [], planes_resumen, cobro_del_mes }) {
    // La contraseña temporal viaja por flash: existe una sola vez y no
    // sobrevive a una recarga.
    const { flash } = usePage().props;
    const [activeTab, setActiveTab] = useState('dashboard');
    const [showCreate, setShowCreate] = useState(false);
    const [editingCompany, setEditingCompany] = useState(null);
    const [planCompany, setPlanCompany] = useState(null);
    const [planForm, setPlanForm] = useState({
        plan: 'inteligente', cobro: 'cortesia',
        contactos_contratados: '', gratis_hasta: '', nota_de_cobro: '',
    });
    
    // Sync tab with URL
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab');
        if (tab && ['dashboard', 'companies', 'plans'].includes(tab)) {
            setActiveTab(tab);
        }
    }, [filters]);

    // La pestaña se queda en la URL para que recargar no te devuelva al
    // dashboard, pero cambiarla no es una visita: las tres llegan en la misma
    // respuesta. Hasta ahora no había ni un solo sitio donde pulsar para
    // cambiar de pestaña —a «empresas» sólo se llegaba escribiendo `?tab=` a
    // mano en la barra del navegador.
    function cambiarPestana(tab) {
        setActiveTab(tab);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    }

    // Ir a otra página de la lista sin recalcular el dashboard, igual que el
    // buscador. El enlace ya trae el filtro puesto porque el paginador del
    // servidor conserva la query.
    function irAPagina(url) {
        if (!url) return;
        router.get(url, {}, { preserveState: true, replace: true, only: LIST_ONLY });
    }

    // Filters State
    const [search, setSearch] = useState(filters?.search ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');
    const [range, setRange] = useState(filters?.range ?? 'month');
    const [startDate, setStartDate] = useState(filters?.start_date ?? '');
    const [endDate, setEndDate] = useState(filters?.end_date ?? '');

    const [createForm, setCreateForm] = useState({ name: '', email: '', admin_name: '', admin_email: '', password: '' });
    const [editForm, setEditForm] = useState({ name: '', email: '', admin_name: '', admin_email: '', password: '', active: false });

    // Ensure numeric data for calculations
    const cleanVolume = useMemo(() => {
        return (messages_volume || []).map(m => ({
            ...m,
            inbound: Number(m.inbound || 0),
            outbound: Number(m.outbound || 0)
        }));
    }, [messages_volume]);

    // Buscar y filtrar por estado sólo cambian la tabla de empresas, así que
    // piden sólo eso. En el servidor los bloques del dashboard son closures:
    // los que no se piden ni se ejecutan, y eso es justo lo que hacía que
    // teclear en el buscador tardara segundos. Lo que quede fuera de esta
    // lista conserva el valor que ya tenía en pantalla.
    const LIST_ONLY = ['companies', 'filters'];

    const applyFilters = useCallback((params, { only } = {}) => {
        router.get(route('master.index'), { 
            search: search, 
            status: status, 
            range: range,
            start_date: startDate,
            end_date: endDate,
            ...params 
        }, { preserveState: true, replace: true, ...(only ? { only } : {}) });
    }, [search, status, range, startDate, endDate]);

    function handleSearchChange(e) {
        const val = e.target.value;
        setSearch(val);
        clearTimeout(window._st);
        window._st = setTimeout(() => applyFilters({ search: val }, { only: LIST_ONLY }), 500);
    }

    function handleRangeChange(e) {
        const val = e.target.value;
        setRange(val);
        applyFilters({ range: val });
    }

    const { data: list } = companies;

    function handleCreate(e) {
        e.preventDefault();
        router.post(route('master.companies.store'), createForm, { onSuccess: () => { setShowCreate(false); setCreateForm({ name: '', email: '', admin_name: '', admin_email: '', password: '' }); } });
    }

    function handleEdit(e) {
        e.preventDefault();
        router.put(route('master.companies.update', editingCompany.id), editForm, { onSuccess: () => setEditingCompany(null) });
    }

    function openPlan(company) {
        const p = company.plan_resumen ?? {};
        setPlanForm({
            plan: p.plan ?? 'inteligente',
            cobro: p.cobro ?? 'cortesia',
            contactos_contratados: p.contactos_contratados ?? '',
            gratis_hasta: p.gratis_hasta ?? '',
            nota_de_cobro: company.nota_de_cobro ?? '',
            viene_de_integra: !!company.viene_de_integra,
        });
        setPlanCompany(company);
    }

    function handlePlan(e) {
        e.preventDefault();
        router.put(route('master.companies.plan', planCompany.id), planForm, {
            onSuccess: () => setPlanCompany(null),
            preserveScroll: true,
        });
    }

    function mesGratis() {
        router.post(route('master.companies.mes-gratis', planCompany.id), {}, {
            onSuccess: () => setPlanCompany(null),
            preserveScroll: true,
        });
    }

    function openEdit(company) {
        const admin = company.users?.[0];
        setEditForm({ name: company.name, email: company.email, admin_name: admin?.name ?? '', admin_email: admin?.email ?? '', password: '', active: !!company.active });
        setEditingCompany(company);

        // Los usuarios de la empresa no vienen en la carga de la página (serían
        // diez consultas para una lista que casi nunca se abre): se piden al
        // abrir el modal, y sólo esa prop.
        router.reload({ only: ['company_users'], data: { users_of: company.id }, replace: true });
    }

    function restablecerContrasena(user) {
        router.post(route('master.users.password', user.id), {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['flash', 'company_users'],
        });
    }

    // --- CHART COMPONENTS ---
    /**
     * El color iba clavado a `#6366f1` —un índigo que no es el de la marca y
     * que en modo oscuro desentona— con un degradado y un `drop-shadow`
     * encima. Ahora hereda `currentColor` del contenedor, así que es el mismo
     * primary que el resto del panel y cambia con el tema.
     */
    const LineChart = ({ data, height = 180 }) => {
        if (!data || data.length === 0) return <div className="flex h-full items-center justify-center text-xs text-muted-foreground">Sin datos en este rango</div>;
        const countData = data.length === 1 ? [{...data[0], x: 0}, {...data[0], x: 100}] : data;
        const max = Math.max(...countData.map(d => Number(d.count)), 2);
        const width = 1000;
        const points = countData.map((d, i) => {
            const x = (i / (countData.length > 1 ? countData.length - 1 : 1)) * width;
            const y = height - ((Number(d.count) / max) * (height * 0.8) + (height * 0.1));
            return `${x},${y}`;
        }).join(' ');
        return (
            <div className="h-full w-full text-primary">
                <svg viewBox={`0 0 ${width} ${height}`} className="h-full w-full" preserveAspectRatio="none">
                    <path d={`M ${points}`} fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" vectorEffect="non-scaling-stroke" />
                    <path d={`M 0,${height} L ${points} L ${width},${height} Z`} fill="currentColor" className="opacity-[0.08]" />
                </svg>
            </div>
        );
    };

    /**
     * El globo del gráfico era una tarjeta con borde de 2px, sombra de 50px y
     * los rótulos «Inbound» y «Outbound» en versales negras de 8px, dentro de
     * un producto que está entero en español. Y al pasar el ratón las barras
     * vecinas se encogían (`scale-x-95`), así que la serie se movía sola
     * mientras se leía.
     */
    const BarChart = ({ data, height = 240 }) => {
        const [activeIdx, setActiveIdx] = useState(null);
        if (!data || data.length === 0) return <div className="flex h-full items-center justify-center text-xs text-muted-foreground">Sin actividad en este rango</div>;

        const max = Math.max(...data.map(d => Math.max(d.inbound, d.outbound)), 5);

        return (
            <div className="relative h-full w-full pt-14">
                {activeIdx !== null && (
                    /* Centrado sobre la barra, salvo en las de los extremos:
                       con `translateX(-50%)` siempre, la primera se salía por la
                       izquierda de la tarjeta y la última por la derecha, justo
                       encima del panel de al lado. */
                    <div
                        className="pointer-events-none absolute top-0 z-30"
                        style={{
                            left: `${(activeIdx / Math.max(data.length - 1, 1)) * 100}%`,
                            transform: `translateX(${
                                activeIdx === 0 ? '0' : activeIdx === data.length - 1 ? '-100%' : '-50%'
                            })`,
                        }}
                    >
                        <div className="min-w-[9rem] rounded-lg border border-border bg-card px-3 py-2 shadow-md">
                            <p className="text-xs text-muted-foreground">{data[activeIdx].date}</p>
                            <dl className="mt-1.5 space-y-0.5">
                                <div className="flex items-center justify-between gap-4">
                                    <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <span className="size-2 rounded-sm bg-primary" /> Entrantes
                                    </dt>
                                    <dd className="text-xs tabular-nums text-foreground">{data[activeIdx].inbound.toLocaleString('es-CO')}</dd>
                                </div>
                                <div className="flex items-center justify-between gap-4">
                                    <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <span className="size-2 rounded-sm bg-success" /> Salientes
                                    </dt>
                                    <dd className="text-xs tabular-nums text-foreground">{data[activeIdx].outbound.toLocaleString('es-CO')}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                )}

                {/* `overflow-hidden` y anchos proporcionales, los dos a
                    propósito. Cada día era un `flex-1` con dos barras de 10px
                    fijos dentro, y un `flex-1` no encoge por debajo de su
                    contenido: con más de cuarenta días la fila medía más que la
                    tarjeta y, sin recorte, las barras sobrantes se pintaban
                    encima del panel de al lado. Ahora las barras ceden ancho
                    —con un tope para que con pocos días no salgan gordas— y lo
                    que aun así se saliera queda cortado dentro de su caja. */}
                <div className="flex h-full w-full items-end gap-px overflow-hidden sm:gap-0.5">
                    {data.map((d, i) => (
                        <div
                            key={i}
                            onMouseEnter={() => setActiveIdx(i)}
                            onMouseLeave={() => setActiveIdx(null)}
                            className={`relative flex h-full min-w-0 flex-1 cursor-default flex-col justify-end transition-opacity ${
                                activeIdx !== null && activeIdx !== i ? 'opacity-40' : 'opacity-100'
                            }`}
                        >
                            <div className="flex h-full w-full items-end justify-center gap-px">
                                <div style={{ height: `${(d.inbound / max) * 100}%` }} className="w-1/2 max-w-[10px] min-w-px rounded-t-sm bg-primary" />
                                <div style={{ height: `${(d.outbound / max) * 100}%` }} className="w-1/2 max-w-[10px] min-w-px rounded-t-sm bg-success" />
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        );
    };

    return (
        <>
            <Head title="Panel master" />
            <div className="flex flex-col min-h-screen bg-muted/10 selection:bg-primary selection:text-primary-foreground">
                
                {/* La cabecera era un cartel: el rayo sobre un cuadrado de 56px
                    con sombra de color, el título en versales negras, un punto
                    verde palpitante con «Integra Cluster Online», un «v2.4.0
                    PRO» que no corresponde a ninguna versión real y dos botones
                    —«Exportar Datos» y «Búsqueda Global»— que no hacían nada al
                    pulsarlos. Un panel interno no tiene a quién impresionar:
                    tiene que decir dónde estás, dejarte cambiar de sitio y
                    quitarse de en medio. */}
                <header className="sticky top-0 z-40 border-b border-border bg-card/95 px-6 py-4 backdrop-blur">
                    <div className="mx-auto flex max-w-[1500px] flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2">
                                <h1 className="font-heading text-lg font-semibold tracking-tight text-foreground">
                                    Panel master
                                </h1>
                                <span className="rounded-md border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                                    Interno
                                </span>
                            </div>
                            <p className="mt-0.5 text-xs text-muted-foreground">{SUBTITULO[activeTab]}</p>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <nav className="flex items-center gap-1 rounded-lg border border-border bg-muted/40 p-1">
                                {PESTANAS.map(({ value, label }) => (
                                    <button
                                        key={value}
                                        type="button"
                                        onClick={() => cambiarPestana(value)}
                                        className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                            activeTab === value
                                                ? 'bg-card text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </nav>
                            <Button onClick={() => setShowCreate(true)} size="sm" className="gap-1.5">
                                <Plus className="size-4" /> Nueva empresa
                            </Button>
                        </div>
                    </div>
                </header>

                <div className="mx-auto w-full max-w-[1500px] space-y-8 p-6">

                    {/* El dashboard hablaba como un folleto: «Reporte de
                        Inteligencia», «Crecimiento de RED», «Métricas Globales»
                        —que eran los usuarios—, cifras en 48px negras dentro de
                        tarjetas de esquinas de 2.5rem que se levantaban al pasar
                        el ratón, y un «+12%» escrito a pelo en el JSX que no se
                        calculaba con nada. Lo que se mira aquí son cuatro
                        números y dos series: eso es lo que tiene que verse. */}
                    {activeTab === 'dashboard' && (
                        <>
                            <div className="flex flex-col gap-3 rounded-xl border border-border bg-card px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 className="font-heading text-sm font-semibold text-foreground">Actividad</h2>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Del {filters.start_date} al {filters.end_date}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <select
                                        value={range}
                                        onChange={handleRangeChange}
                                        className="h-9 rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none transition-colors focus:border-ring focus:ring-2 focus:ring-ring/20"
                                    >
                                        <option value="week">Última semana</option>
                                        <option value="month">Último mes</option>
                                        <option value="year">Último año</option>
                                        <option value="custom">Rango a medida</option>
                                    </select>
                                    {range === 'custom' && (
                                        <div className="flex items-center gap-2">
                                            <input type="date" value={startDate} onChange={e => setStartDate(e.target.value)} className="h-9 rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/20" />
                                            <span className="text-xs text-muted-foreground">al</span>
                                            <input type="date" value={endDate} onChange={e => setEndDate(e.target.value)} className="h-9 rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/20" />
                                            <Button size="sm" variant="outline" onClick={() => applyFilters()}>Aplicar</Button>
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                                <KPICard label="Empresas" value={stats.total_companies} sub={`${stats.active_companies} activas`} icon={<Briefcase className="size-4" />} />
                                <KPICard label="Instancias" value={stats.total_instances} sub={`${stats.active_instances} activas`} icon={<Layers className="size-4" />} />
                                <KPICard label="Mensajes en el rango" value={stats.total_messages_range.toLocaleString('es-CO')} sub="Entrantes y salientes" icon={<MessageSquare className="size-4" />} />
                                <KPICard label="Usuarios" value={stats.total_users} sub="De todas las empresas" icon={<Users className="size-4" />} />
                            </div>

                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                                <section className="rounded-xl border border-border bg-card p-5 lg:col-span-8">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <h3 className="font-heading text-sm font-semibold text-foreground">Volumen de mensajes</h3>
                                        {/* «Inbound» y «Outbound» en un producto que
                                            está entero en español, y en la pantalla
                                            de quien lo vende. */}
                                        <div className="flex items-center gap-4 text-xs text-muted-foreground">
                                            <span className="flex items-center gap-1.5">
                                                <span className="size-2 rounded-sm bg-primary" /> Entrantes
                                            </span>
                                            <span className="flex items-center gap-1.5">
                                                <span className="size-2 rounded-sm bg-success" /> Salientes
                                            </span>
                                        </div>
                                    </div>
                                    <div className="mt-6 h-64"><BarChart data={cleanVolume} height={240} /></div>
                                    <div className="mt-5 grid grid-cols-3 divide-x divide-border border-t border-border pt-4">
                                        <StatLabel label="Pico máximo" value={Math.max(...cleanVolume.map(m => m.inbound + m.outbound), 0).toLocaleString('es-CO')} />
                                        <StatLabel label="Promedio diario" value={Math.round(cleanVolume.reduce((a, b) => a + (b.inbound + b.outbound), 0) / (cleanVolume.length || 1)).toLocaleString('es-CO')} />
                                        <StatLabel label="Total del periodo" value={cleanVolume.reduce((a, b) => a + (b.inbound + b.outbound), 0).toLocaleString('es-CO')} />
                                    </div>
                                </section>

                                <section className="rounded-xl border border-border bg-card p-5 lg:col-span-4">
                                    <h3 className="font-heading text-sm font-semibold text-foreground">Empresas con más actividad</h3>
                                    <p className="mt-0.5 text-xs text-muted-foreground">Mensajes en el rango elegido.</p>
                                    {top_companies.length === 0 ? (
                                        <p className="mt-6 text-xs text-muted-foreground">Sin actividad en este rango.</p>
                                    ) : (
                                        <ul className="mt-5 space-y-4">
                                            {top_companies.map((co) => (
                                                <li key={co.id}>
                                                    <div className="flex items-center justify-between gap-3">
                                                        <span className="truncate text-sm text-foreground">{co.name}</span>
                                                        <span className="shrink-0 text-sm tabular-nums text-muted-foreground">
                                                            {Number(co.messages_count || 0).toLocaleString('es-CO')}
                                                        </span>
                                                    </div>
                                                    <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className="h-full rounded-full bg-primary"
                                                            style={{ width: `${Math.max((Number(co.messages_count || 1) / (Number(top_companies[0].messages_count || 1))) * 100, 2)}%` }}
                                                        />
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>
                            </div>

                            <section className="rounded-xl border border-border bg-card p-5">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 className="font-heading text-sm font-semibold text-foreground">Empresas nuevas</h3>
                                        <p className="mt-0.5 text-xs text-muted-foreground">Altas en el rango elegido.</p>
                                    </div>
                                    <span className="text-xs tabular-nums text-muted-foreground">
                                        {companies_growth.reduce((a, b) => a + Number(b.count), 0)} en total
                                    </span>
                                </div>
                                <div className="mt-6 h-40"><LineChart data={companies_growth} height={160} /></div>
                            </section>
                        </>
                    )}

                    {/* El directorio era una tarjeta de esquinas de 2.5rem con
                        40px de relleno, cabeceras en versales negras con cuatro
                        décimas de interletraje y filas de 112px de alto: seis
                        empresas llenaban la pantalla de un portátil. Las
                        acciones, además, vivían a `opacity-20` hasta pasar el
                        ratón por encima, así que la fila parecía no tener
                        ninguna. Ahora es una tabla: densidad normal, jerarquía
                        por peso y color en vez de por tamaño, y los botones
                        siempre visibles. */}
                    {activeTab === 'companies' && (
                        <section className="overflow-hidden rounded-xl border border-border bg-card">
                            <div className="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center">
                                <div className="relative flex-1 sm:max-w-md">
                                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        type="text"
                                        placeholder="Buscar por empresa, correo o administrador"
                                        value={search}
                                        onChange={handleSearchChange}
                                        className="h-9 w-full rounded-lg border border-input bg-background pl-9 pr-3 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground/70 focus:border-ring focus:ring-2 focus:ring-ring/20"
                                    />
                                </div>
                                <select
                                    value={status}
                                    onChange={e => { setStatus(e.target.value); applyFilters({ status: e.target.value }, { only: LIST_ONLY }); }}
                                    className="h-9 rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none transition-colors focus:border-ring focus:ring-2 focus:ring-ring/20"
                                >
                                    <option value="">Todos los estados</option>
                                    <option value="active">Activas</option>
                                    <option value="inactive">Inactivas</option>
                                </select>
                                <span className="text-xs tabular-nums text-muted-foreground sm:ml-auto">
                                    {companies.total} {companies.total === 1 ? 'empresa' : 'empresas'}
                                </span>
                            </div>

                            {list.length === 0 ? (
                                <div className="px-5 py-16 text-center">
                                    <p className="text-sm text-muted-foreground">
                                        {search || status
                                            ? 'Ninguna empresa coincide con lo que buscas.'
                                            : 'Todavía no hay empresas.'}
                                    </p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-border bg-muted/40 text-xs text-muted-foreground">
                                                <th className="px-5 py-2.5 text-left font-medium">Empresa</th>
                                                <th className="hidden px-5 py-2.5 text-left font-medium lg:table-cell">Administrador</th>
                                                <th className="hidden px-5 py-2.5 text-left font-medium md:table-cell">Plan y cobro</th>
                                                <th className="px-5 py-2.5 text-left font-medium">Estado</th>
                                                <th className="px-5 py-2.5 text-right font-medium">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {list.map(company => (
                                                <tr key={company.id} className="border-b border-border/60 last:border-0 hover:bg-muted/30">
                                                    <td className="px-5 py-3">
                                                        <div className="flex items-center gap-3">
                                                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted text-sm font-semibold text-muted-foreground">
                                                                {company.name.charAt(0).toUpperCase()}
                                                            </div>
                                                            <div className="min-w-0">
                                                                <p className="truncate font-medium text-foreground">{company.name}</p>
                                                                <p className="truncate text-xs text-muted-foreground">{company.email}</p>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="hidden px-5 py-3 lg:table-cell">
                                                        {company.users?.[0] ? (
                                                            <div className="min-w-0">
                                                                <p className="truncate text-foreground">{company.users[0].name}</p>
                                                                <p className="truncate text-xs text-muted-foreground">{company.users[0].email}</p>
                                                            </div>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">Sin administrador</span>
                                                        )}
                                                        <p className="mt-0.5 text-xs tabular-nums text-muted-foreground">
                                                            {company.instances_count} inst. · {company.users_count} usuarios
                                                        </p>
                                                    </td>
                                                    <td className="hidden px-5 py-3 md:table-cell">
                                                        <PastillaDePlan resumen={company.plan_resumen} uso={company.uso_ia} />
                                                    </td>
                                                    <td className="px-5 py-3">
                                                        <span className={`inline-flex items-center gap-1.5 text-xs ${company.active ? 'text-foreground' : 'text-muted-foreground'}`}>
                                                            <span className={`size-1.5 rounded-full ${company.active ? 'bg-success' : 'bg-muted-foreground/40'}`} />
                                                            {company.active ? 'Activa' : 'Inactiva'}
                                                        </span>
                                                    </td>
                                                    <td className="px-5 py-3">
                                                        <div className="flex items-center justify-end gap-1">
                                                            <Button variant="ghost" size="icon" className="size-8 text-muted-foreground hover:text-foreground" title="Plan y cobro" onClick={() => openPlan(company)}>
                                                                <CreditCard className="size-4" />
                                                            </Button>
                                                            <Button variant="ghost" size="icon" className="size-8 text-muted-foreground hover:text-foreground" title="Editar datos" onClick={() => openEdit(company)}>
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                            <Button variant="outline" size="sm" className="ml-1 gap-1.5" title={`Entrar como ${company.name}`} onClick={() => router.post(route('master.impersonate', company.id))}>
                                                                <LogIn className="size-3.5" /> Entrar
                                                            </Button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {/* El paginador faltaba entero: el servidor manda las
                                empresas de diez en diez y la pantalla sólo pintaba
                                la primera página, así que a partir de la undécima
                                empresa la única forma de llegar a una era buscarla
                                por nombre. */}
                            {companies.last_page > 1 && (
                                <div className="flex items-center justify-between gap-3 border-t border-border px-5 py-3">
                                    <p className="text-xs tabular-nums text-muted-foreground">
                                        {companies.from}–{companies.to} de {companies.total}
                                    </p>
                                    <div className="flex items-center gap-2">
                                        <Button variant="outline" size="sm" disabled={!companies.prev_page_url} onClick={() => irAPagina(companies.prev_page_url)}>
                                            Anterior
                                        </Button>
                                        <span className="text-xs tabular-nums text-muted-foreground">
                                            {companies.current_page} / {companies.last_page}
                                        </span>
                                        <Button variant="outline" size="sm" disabled={!companies.next_page_url} onClick={() => irAPagina(companies.next_page_url)}>
                                            Siguiente
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </section>
                    )}

                    {/* La pestaña llevaba tres tarjetas y una tabla escritas a
                        pelo aquí mismo: «Startup 49,99 USD, 12 suscripciones»,
                        «Business Pro», «Enterprise». Ni los planes eran los del
                        producto —Esencial, Automatización, Inteligente— ni esas
                        cifras se habían cobrado nunca, ni los conteos venían de
                        la base de datos. El botón «Nuevo Plan» tampoco hacía
                        nada, y no puede hacerlo: los planes viven en
                        `config/planes.php` a propósito, para que una fila no
                        pueda quedar desincronizada del catálogo de extensiones. */}
                    {activeTab === 'plans' && (
                        <div className="space-y-6">
                            {/* Los tres tamaños de CRM. Precio fijo y no un rango:
                                un rango se lee como «depende» o como negociable, y
                                lo que hace falta delante de un cliente es un
                                número. La escalera de quince precios que había
                                aquí —cinco tramos por tres planes— se retiró el
                                15-sep-2026 junto con los tramos. */}
                            <div className="grid gap-4 md:grid-cols-3">
                                {planes_resumen.planes.map(plan => (
                                    <section key={plan.slug} className="flex flex-col rounded-xl border border-border bg-card p-5">
                                        <div className="flex items-start justify-between gap-3">
                                            <h3 className="font-heading text-base font-semibold text-foreground">{plan.nombre}</h3>
                                            <span className="shrink-0 rounded-md border border-border bg-muted px-2 py-0.5 text-xs tabular-nums text-muted-foreground">
                                                {plan.empresas} {plan.empresas === 1 ? 'empresa' : 'empresas'}
                                            </span>
                                        </div>

                                        <div className="mt-4 border-y border-border py-3">
                                            <p className="flex items-baseline gap-1.5">
                                                <span className="text-3xl font-semibold tabular-nums text-foreground">
                                                    ${plan.precio}
                                                </span>
                                                <span className="text-xs text-muted-foreground">USD al mes</span>
                                            </p>
                                            <p className="mt-1 text-[11px] text-muted-foreground">
                                                Pagando el año, dos meses gratis: ${Math.round((plan.precio * (12 - planes_resumen.meses_gratis_al_pagar_anual)) / 12)} al mes.
                                            </p>
                                        </div>

                                        <ul className="mt-4 space-y-1.5 text-xs text-muted-foreground">
                                            <li><span className="font-semibold tabular-nums text-foreground">{plan.agentes}</span> agentes</li>
                                            <li><span className="font-semibold tabular-nums text-foreground">{plan.contactos.toLocaleString('es-CO')}</span> contactos</li>
                                            <li><span className="font-semibold tabular-nums text-foreground">{plan.lineas}</span> {plan.lineas === 1 ? 'línea' : 'líneas'}</li>
                                            <li className="pt-1 text-[11px]">
                                                Con complemento: {plan.credito_ia.toLocaleString('es-CO')} conversaciones con IA al mes
                                            </li>
                                        </ul>

                                        {plan.facturando > 0 && (
                                            <p className="mt-4 text-[11px] text-muted-foreground">
                                                <span className="font-semibold text-foreground">{plan.facturando}</span> facturando
                                            </p>
                                        )}
                                    </section>
                                ))}
                            </div>

                            {/* El complemento, aparte. Es lo que se vende: al cliente
                                de Integra el CRM ya se lo cobró el ERP. */}
                            <section className="rounded-xl border border-border bg-card">
                                <div className="border-b border-border px-5 py-4">
                                    <h3 className="font-heading text-sm font-semibold text-foreground">Complemento de IA</h3>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Se vende aparte del plan y se suma al precio. De las {planes_resumen.total_empresas} empresas,{' '}
                                        <span className="font-semibold text-foreground">{planes_resumen.de_integra}</span> vienen de Integra
                                        y ya pagan el CRM dentro de su ERP: lo único nuevo que se les puede vender es esto.
                                    </p>
                                </div>
                                <ul className="divide-y divide-border">
                                    {planes_resumen.complementos.map(c => (
                                        <li key={c.slug} className="flex items-center justify-between gap-3 px-5 py-3">
                                            <span className="text-sm text-foreground">{c.nombre}</span>
                                            <span className="flex items-center gap-4">
                                                <span className="text-sm tabular-nums text-muted-foreground">
                                                    {c.precio ? `+$${c.precio}/mes` : '—'}
                                                </span>
                                                <span className="w-20 text-right text-sm tabular-nums text-muted-foreground">
                                                    {c.empresas} {c.empresas === 1 ? 'empresa' : 'empresas'}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>

                            {/* Lo que va en los tres planes. Sin esto, el plan de
                                entrada se lee como un plan de una sola función. */}
                            <section className="rounded-xl border border-border bg-card px-5 py-4">
                                <h3 className="font-heading text-sm font-semibold text-foreground">En los tres planes</h3>
                                <ul className="mt-3 grid gap-x-6 gap-y-1.5 text-xs text-muted-foreground sm:grid-cols-2">
                                    {planes_resumen.nucleo.map(linea => (
                                        <li key={linea}>· {linea}</li>
                                    ))}
                                </ul>
                            </section>


                                pestaña: lo demás es catálogo. */}
                            <CobroDelMes datos={cobro_del_mes} />

                            <section className="rounded-xl border border-border bg-card lg:max-w-md">
                                    <div className="border-b border-border px-5 py-4">
                                        <h3 className="font-heading text-sm font-semibold text-foreground">Estado de cobro</h3>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {planes_resumen.total_empresas} empresas en total. Suspendido no apaga nada:
                                            sólo marca a quien no ha pagado.
                                        </p>
                                    </div>
                                    <ul className="divide-y divide-border">
                                        {Object.entries(planes_resumen.cobros).map(([cobro, total]) => (
                                            <li key={cobro} className="flex items-center justify-between gap-3 px-5 py-3">
                                                <span className="text-sm text-foreground">{ETIQUETA_COBRO[cobro] ?? cobro}</span>
                                                <span className="text-sm tabular-nums text-muted-foreground">{total}</span>
                                            </li>
                                        ))}
                                    </ul>
                            </section>

                            <p className="max-w-3xl text-xs leading-relaxed text-muted-foreground">
                                Los planes y sus tramos viven en <code className="font-mono">config/planes.php</code>, no
                                en la base de datos: son una regla de producto, y una fila que pueda quedar
                                desincronizada del catálogo de extensiones sólo añade formas de fallar. Lo que sí se
                                cambia desde aquí es el plan de cada empresa, en su ficha.
                            </p>
                        </div>
                    )}
                </div>
                
            </div>

            {/* Modals for Create/Edit Company */}
            {showCreate && (
                <Modal
                    title="Nueva empresa"
                    description="Se crea la empresa y, con ella, el usuario que la administrará."
                    onClose={() => setShowCreate(false)}
                    footer={
                        <>
                            <Button type="button" variant="outline" onClick={() => setShowCreate(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" form="form-crear-empresa" className="gap-1.5">
                                <Plus className="size-4" />
                                Crear empresa
                            </Button>
                        </>
                    }
                >
                    <form id="form-crear-empresa" onSubmit={handleCreate} className="space-y-5">
                        <div className="space-y-4">
                            <Field
                                label="Nombre de la empresa"
                                value={createForm.name}
                                onChange={v => setCreateForm(f => ({ ...f, name: v }))}
                                placeholder="Redes del Sur SAS"
                                required
                            />
                            <Field
                                label="Correo de la empresa"
                                type="email"
                                value={createForm.email}
                                onChange={v => setCreateForm(f => ({ ...f, email: v }))}
                                placeholder="contacto@empresa.com"
                                required
                            />
                        </div>

                        <BloqueAdmin titulo="Quien la administrará">
                            <Field
                                label="Nombre"
                                value={createForm.admin_name}
                                onChange={v => setCreateForm(f => ({ ...f, admin_name: v }))}
                                placeholder="María Restrepo"
                                required
                            />
                            <Field
                                label="Correo"
                                type="email"
                                value={createForm.admin_email}
                                onChange={v => setCreateForm(f => ({ ...f, admin_email: v }))}
                                placeholder="maria@empresa.com"
                                ayuda="Con este correo entrará al CRM."
                                required
                            />
                            <Field
                                label="Contraseña"
                                type="password"
                                value={createForm.password}
                                onChange={v => setCreateForm(f => ({ ...f, password: v }))}
                                autoComplete="new-password"
                                required
                            />
                        </BloqueAdmin>
                    </form>
                </Modal>
            )}
            {planCompany && (
                <Modal
                    title={`Plan de ${planCompany.name}`}
                    description="Qué puede usar y si se le cobra. Son dos cosas distintas."
                    onClose={() => setPlanCompany(null)}
                    footer={
                        <>
                            <Button type="button" variant="outline" onClick={() => setPlanCompany(null)}>
                                Cancelar
                            </Button>
                            <Button type="submit" form="form-plan">Guardar</Button>
                        </>
                    }
                >
                    <form id="form-plan" onSubmit={handlePlan} className="space-y-5">

                        {planCompany.uso_ia?.incluidas > 0 && (
                            <div className="rounded-lg border border-border bg-muted/40 px-4 py-3">
                                <div className="flex items-baseline justify-between gap-3">
                                    <span className="text-xs font-semibold text-foreground">IA este mes</span>
                                    <span className="font-mono text-xs tabular-nums text-muted-foreground">
                                        {planCompany.uso_ia.usadas} / {planCompany.uso_ia.incluidas} conversaciones
                                    </span>
                                </div>
                                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-border">
                                    <div
                                        className={`h-full rounded-full ${planCompany.uso_ia.exceso > 0 ? 'bg-warning' : 'bg-primary'}`}
                                        style={{ width: `${Math.min(100, planCompany.uso_ia.porcentaje)}%` }}
                                    />
                                </div>
                                <p className="mt-2 text-[11px] leading-snug text-muted-foreground">
                                    {planCompany.uso_ia.exceso > 0
                                        ? `${planCompany.uso_ia.exceso} de exceso para facturar. El servicio sigue funcionando.`
                                        : `Coste hasta hoy: $${planCompany.uso_ia.coste_usd}`}
                                </p>
                            </div>
                        )}

                        <Selector
                            label="Plan"
                            value={planForm.plan}
                            onChange={v => setPlanForm({ ...planForm, plan: v })}
                            options={planes}
                            ayuda="Qué extensiones puede instalar. Bajarlo no desinstala lo que ya tenga."
                        />

                        {planCompany.plan_resumen?.precio_usd && (
                            <p className="-mt-2 text-xs text-muted-foreground">
                                Con su tramo actual le corresponden{' '}
                                <span className="font-mono font-semibold text-foreground">
                                    ${planCompany.plan_resumen.precio_usd}
                                </span>{' '}
                                al mes, o{' '}
                                <span className="font-mono font-semibold text-foreground">
                                    ${planCompany.plan_resumen.precio_usd_anual}
                                </span>{' '}
                                pagando el año.
                            </p>
                        )}

                        {/* Va encima del cobro porque lo decide: a un cliente de
                            Integra el CRM ya se le cobró dentro del ERP. */}
                        <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-muted/30 px-4 py-3">
                            <input
                                type="checkbox"
                                checked={planForm.viene_de_integra}
                                onChange={e => setPlanForm({
                                    ...planForm,
                                    viene_de_integra: e.target.checked,
                                    // Marcarlo y dejar el cobro en cortesía es una
                                    // contradicción que sólo se ve un mes después,
                                    // en la lista de cobro.
                                    cobro: e.target.checked && planForm.cobro === 'cortesia'
                                        ? 'integra'
                                        : planForm.cobro,
                                })}
                                className="mt-0.5 size-4 shrink-0 accent-primary"
                            />
                            <span className="min-w-0">
                                <span className="block text-sm font-semibold text-foreground">
                                    Viene de Integra
                                </span>
                                <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                                    Se le vendió el ERP con el CRM dentro, así que ya paga. No se le
                                    factura aquí y no aparece en la lista de cobro. Lo que sí se le
                                    puede vender es el complemento de IA.
                                </span>
                            </span>
                        </label>

                        <Selector
                            label="Cobro"
                            value={planForm.cobro}
                            onChange={v => setPlanForm({ ...planForm, cobro: v })}
                            options={cobros.map(c => ({ value: c, label: ETIQUETA_COBRO[c] ?? c }))}
                            ayuda="Integra es quien ya paga por el ERP. Cortesía es a quien todavía no se le cobra — son cosas distintas. Suspendido no apaga nada, solo marca."
                        />

                        <div className="space-y-1.5">
                            <Field
                                label="Contactos contratados"
                                type="number"
                                value={planForm.contactos_contratados}
                                onChange={v => setPlanForm({ ...planForm, contactos_contratados: v })}
                                placeholder="12000"
                                ayuda="Cuántos contactos atiende por WhatsApp. Es el tramo de la escalera: decide el precio y el crédito de IA. No es un límite, nadie deja de atender por crecer."
                            />

                            {/* Lo que tiene de verdad, al lado de lo que contrató.
                                El dato está ahí desde siempre —cada persona que
                                escribe queda como contacto— pero nadie lo miraba al
                                poner el tramo, así que se ponía a ojo. */}
                            {planCompany.plan_resumen?.contactos_reales > 0 && (
                                <div className={`rounded-lg border px-3 py-2 text-xs ${
                                    planCompany.plan_resumen.se_paso_del_tramo
                                        ? 'border-warning/40 bg-warning/10'
                                        : 'border-border bg-muted/40'
                                }`}>
                                    <div className="flex items-baseline justify-between gap-3">
                                        <span className="text-foreground">Tiene ahora mismo</span>
                                        <span className="font-mono tabular-nums font-semibold text-foreground">
                                            {planCompany.plan_resumen.contactos_reales.toLocaleString('es-CO')}
                                        </span>
                                    </div>
                                    {planCompany.plan_resumen.se_paso_del_tramo ? (
                                        <p className="mt-1 leading-snug text-muted-foreground">
                                            Se pasó de lo contratado. Toca renegociar el tramo — no se le
                                            corta nada mientras tanto.
                                        </p>
                                    ) : planCompany.plan_resumen.tramo_sugerido && (
                                        <button
                                            type="button"
                                            onClick={() => setPlanForm({
                                                ...planForm,
                                                contactos_contratados: planCompany.plan_resumen.tramo_sugerido,
                                            })}
                                            className="mt-1 text-primary hover:underline"
                                        >
                                            Usar el tramo que le corresponde
                                            ({planCompany.plan_resumen.tramo_sugerido.toLocaleString('es-CO')})
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-xs font-semibold text-foreground">Gratis hasta</label>
                            <div className="flex items-center gap-2">
                                <input
                                    id="gratis-hasta"
                                    type="date"
                                    value={planForm.gratis_hasta ?? ''}
                                    onChange={e => setPlanForm({ ...planForm, gratis_hasta: e.target.value })}
                                    className="h-10 flex-1 rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/20"
                                />
                                <Button type="button" variant="outline" onClick={mesGratis} className="h-10 whitespace-nowrap">
                                    + 1 mes
                                </Button>
                            </div>
                            <p className="text-[11px] leading-snug text-muted-foreground">
                                Mientras no pase, no se factura aunque el cobro esté activo. El botón suma un mes al que ya hubiera y guarda al momento.
                            </p>
                        </div>

                        <Field
                            label="Nota"
                            value={planForm.nota_de_cobro}
                            onChange={v => setPlanForm({ ...planForm, nota_de_cobro: v })}
                            placeholder="Cliente desde 2024, cortesía hasta que firmen"
                            ayuda="Por qué está así. Dentro de un año nadie se va a acordar, y sin esto acaba en un WhatsApp."
                        />
                    </form>
                </Modal>
            )}

            {editingCompany && (
                <Modal
                    title={editingCompany.name}
                    description="Datos de la empresa y de quien la administra."
                    onClose={() => setEditingCompany(null)}
                    footer={
                        <>
                            <Button type="button" variant="outline" onClick={() => setEditingCompany(null)}>
                                Cancelar
                            </Button>
                            <Button type="submit" form="form-editar-empresa">
                                Guardar cambios
                            </Button>
                        </>
                    }
                >
                    <form id="form-editar-empresa" onSubmit={handleEdit} className="space-y-5">
                        <div className="space-y-4">
                            <Field
                                label="Nombre de la empresa"
                                value={editForm.name}
                                onChange={v => setEditForm(f => ({ ...f, name: v }))}
                                required
                            />
                            <Field
                                label="Correo de la empresa"
                                type="email"
                                value={editForm.email}
                                onChange={v => setEditForm(f => ({ ...f, email: v }))}
                                required
                            />
                        </div>

                        <BloqueAdmin titulo="Quien la administra">
                            <Field
                                label="Nombre"
                                value={editForm.admin_name}
                                onChange={v => setEditForm(f => ({ ...f, admin_name: v }))}
                                required
                            />
                            <Field
                                label="Correo"
                                type="email"
                                value={editForm.admin_email}
                                onChange={v => setEditForm(f => ({ ...f, admin_email: v }))}
                                required
                            />
                            <Field
                                label="Contraseña"
                                type="password"
                                value={editForm.password}
                                onChange={v => setEditForm(f => ({ ...f, password: v }))}
                                autoComplete="new-password"
                                ayuda="Déjala vacía para no cambiarla."
                            />
                        </BloqueAdmin>
                    </form>
                    <div className="mt-6 border-t border-border pt-5">
                        <UsuariosDeLaEmpresa
                            usuarios={company_users}
                            flash={flash}
                            onRestablecer={restablecerContrasena}
                        />
                    </div>
                </Modal>
            )}
        </>
    );
}

/** Los tres totales bajo el gráfico. El separador lo pone la rejilla. */
function StatLabel({ label, value }) {
    return (
        <div className="px-4 first:pl-0 last:pr-0">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-0.5 text-lg font-semibold tabular-nums tracking-tight text-foreground">{value}</p>
        </div>
    );
}

/**
 * Un número del resumen.
 *
 * Era una tarjeta de 36px de relleno con esquinas de 2.5rem, un icono de 56px
 * arriba, la cifra en 48px negras y la etiqueta en versales con dos décimas de
 * interletraje; cuatro de ellas no cabían en una fila sin scroll. Y aceptaba un
 * `trend` que sólo se usaba una vez, con un «+12%» escrito a mano que no salía
 * de ningún cálculo: se ha quitado el parámetro para que no vuelva a colarse
 * una cifra inventada por la puerta de atrás.
 */
function KPICard({ label, value, sub, icon }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex items-center gap-2 text-muted-foreground">
                {icon}
                <span className="text-xs font-medium">{label}</span>
            </div>
            <p className="mt-3 text-2xl font-semibold tabular-nums tracking-tight text-foreground">{value}</p>
            {sub && <p className="mt-0.5 text-xs text-muted-foreground">{sub}</p>}
        </div>
    );
}

/**
 * El diálogo de las empresas.
 *
 * Era un cartel: esquinas de 3rem, 56px de relleno, una franja de degradado
 * arriba y el título centrado en versales enormes. Todo eso ocupaba media
 * pantalla antes del primer campo, y el conjunto se parecía más a una portada
 * que a un formulario de administración.
 *
 * Ahora la cabecera es una barra con el título a la izquierda y la X a la
 * derecha, y se queda fija al desplazar: el diálogo de edición es largo —lleva
 * la lista de usuarios— y al bajar se perdía de vista qué se estaba editando.
 * El pie hace lo mismo con los botones, que antes había que ir a buscar al
 * final del todo.
 */
function Modal({ title, description, onClose, footer, children }) {
    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-background/70 backdrop-blur-sm p-4 animate-in fade-in duration-200"
            onClick={onClose}
        >
            <div
                className="flex w-full max-w-lg max-h-[88vh] flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-2xl animate-in zoom-in-95 duration-200"
                onClick={e => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
            >
                <header className="flex items-start gap-4 border-b border-border px-6 py-4">
                    <div className="min-w-0 flex-1">
                        <h2 className="font-heading text-base font-bold tracking-tight text-foreground">
                            {title}
                        </h2>
                        {description && (
                            <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="-mr-1 -mt-1 shrink-0 rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <XIcon className="size-4" />
                    </button>
                </header>

                {/* El scroll vive aquí y no en el diálogo entero, para que la
                    cabecera y el pie no se muevan. */}
                <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>

                {footer && (
                    <footer className="flex items-center justify-end gap-2 border-t border-border bg-muted/30 px-6 py-3">
                        {footer}
                    </footer>
                )}
            </div>
        </div>
    );
}

/**
 * Los usuarios de la empresa, con un botón para restablecerles la contraseña.
 *
 * Vive dentro del modal de edición porque es donde ya se cambia la del admin;
 * lo que añade es el resto de la plantilla del cliente, que antes sólo podía
 * ayudar su propio admin — y si el que se había quedado fuera era el admin, no
 * había a quién pedírselo.
 *
 * La contraseña generada se muestra una sola vez, aquí mismo: no se guarda en
 * ningún sitio en claro y al recargar el modal ya no está.
 */
function UsuariosDeLaEmpresa({ usuarios, flash, onRestablecer }) {
    const [copiada, setCopiada] = useState(false);

    const copiar = () => {
        navigator.clipboard?.writeText(flash.temp_password);
        setCopiada(true);
        setTimeout(() => setCopiada(false), 2000);
    };

    return (
        /* El modal que lo contiene ya se pasó a un diálogo sobrio, pero esta
           sección se quedó atrás: rótulo en versales negras, la contraseña en
           un recuadro de esquinas de 1rem y cada usuario en una tarjeta de
           56px con tres pastillas. Dentro del mismo formulario se notaba que
           eran de dos épocas. Ahora es una lista. */
        <div className="space-y-4">
            <div className="flex items-center gap-2">
                <KeyRound className="size-3.5 text-muted-foreground" />
                <h3 className="text-xs font-semibold text-foreground">Usuarios de la empresa</h3>
            </div>

            {flash?.temp_password && (
                <div className="space-y-3 rounded-xl border border-warning/40 bg-warning/[0.06] p-4">
                    <div className="flex items-start gap-2">
                        <ShieldAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                        <p className="text-xs leading-relaxed text-foreground">
                            Contraseña temporal de <span className="font-medium">{flash.temp_password_for}</span>.
                            Se muestra una sola vez: cópiala y pide que la cambien al entrar.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <code className="flex-1 select-all rounded-lg border border-border bg-background px-3 py-2 font-mono text-sm tracking-wide text-foreground">
                            {flash.temp_password}
                        </code>
                        <Button type="button" variant="outline" size="sm" onClick={copiar} className="gap-1.5">
                            <Copy className="size-3.5" />
                            {copiada ? 'Copiada' : 'Copiar'}
                        </Button>
                    </div>
                </div>
            )}

            {!usuarios || usuarios.length === 0 ? (
                <p className="text-xs text-muted-foreground">Esta empresa no tiene usuarios.</p>
            ) : (
                <ul className="divide-y divide-border rounded-xl border border-border">
                    {usuarios.map(u => (
                        <li key={u.id} className="flex items-center justify-between gap-3 px-4 py-3">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <span className="truncate text-sm font-medium text-foreground">{u.name}</span>
                                    <span className="shrink-0 rounded-md border border-border bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                        {u.role}
                                    </span>
                                    {!u.active && (
                                        <span className="shrink-0 rounded-md bg-destructive/10 px-1.5 py-0.5 text-[10px] text-destructive">
                                            Inactivo
                                        </span>
                                    )}
                                </div>
                                <p className="truncate text-xs text-muted-foreground">{u.email}</p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="shrink-0"
                                onClick={() => onRestablecer(u)}
                            >
                                Restablecer
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Un campo del formulario.
 *
 * Los campos medían 56px de alto con 24px de sangrado y el texto iba en
 * monoespaciada y negrita: un nombre de empresa y un correo no son código, y
 * en esa tipografía se leen peor. Ahora usan la altura y la fuente del resto
 * del producto, y la ayuda opcional evita tener que adivinar para qué sirve un
 * campo por su etiqueta de dos palabras.
 */
/**
 * Los datos del administrador, agrupados.
 *
 * Iban dentro de un recuadro con `bg-primary/50`: el verde de la marca a media
 * opacidad, un bloque de color plano que se comía la mitad del formulario y no
 * decía por qué esos tres campos van juntos. Ahora es una superficie neutra con
 * un rótulo que lo explica — son los datos de una persona, no de la empresa.
 */
function BloqueAdmin({ titulo, children }) {
    return (
        <section className="rounded-xl border border-border bg-muted/40 p-4">
            <div className="mb-3 flex items-center gap-2">
                <UserCog className="size-3.5 text-muted-foreground" />
                <p className="text-xs font-semibold text-foreground">{titulo}</p>
            </div>
            <div className="space-y-4">{children}</div>
        </section>
    );
}

const PESTANAS = [
    { value: 'dashboard', label: 'Resumen' },
    { value: 'companies', label: 'Empresas' },
    { value: 'plans', label: 'Planes' },
];

const SUBTITULO = {
    dashboard: 'Actividad de toda la plataforma.',
    companies: 'Las empresas del sistema, su plan y su administrador.',
    plans: 'Catálogo comercial y suscripciones.',
};

/**
 * A quién hay que cobrarle este mes.
 *
 * Es lo único accionable de esta pestaña: el resto es catálogo. Enseña la lista
 * y, al lado, por qué los demás no entran — que mientras dure la transición son
 * casi todos, y es el número que dice si la transición avanza o está parada.
 *
 * La factura se emite fuera del CRM, así que el botón que importa es el de
 * descargar: lo que sale de aquí se lo lleva quien factura.
 */
function CobroDelMes({ datos }) {
    if (!datos) return null;

    const { cobrar = [], fuera = [], total_usd = 0, sin_tramo = 0 } = datos;

    const porMotivo = fuera.reduce((acc, f) => {
        acc[f.motivo] = (acc[f.motivo] ?? 0) + 1;
        return acc;
    }, {});

    return (
        <section className="rounded-xl border border-border bg-card">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div className="min-w-0">
                    <h3 className="font-heading text-sm font-semibold text-foreground">A cobrar este mes</h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        La factura se emite fuera del CRM. Esto dice a quién y cuánto.
                    </p>
                </div>

                {cobrar.length > 0 && (
                    <Button asChild variant="outline" size="sm" className="shrink-0 gap-2">
                        <a href={route('master.cobro.csv')} download>
                            <Download className="size-3.5" /> Descargar CSV
                        </a>
                    </Button>
                )}
            </div>

            {cobrar.length === 0 ? (
                /* El caso de hoy, y no es un error: enseñarlo vacío sin explicar
                   por qué haría pensar que el cálculo está roto. */
                <div className="px-5 py-6">
                    <p className="text-sm font-semibold text-foreground">Nadie, todavía.</p>
                    <p className="mt-1 max-w-xl text-xs leading-relaxed text-muted-foreground">
                        Ninguna empresa tiene el cobro en «Activo». Es lo esperado durante la
                        transición: se va pasando a activo una a una, desde la ficha de cada
                        empresa, según se vayan cerrando los acuerdos.
                    </p>
                </div>
            ) : (
                <>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b border-border text-[10px] font-black uppercase tracking-widest text-muted-foreground/60">
                                <tr>
                                    <th className="px-5 py-2.5 text-left font-black">Empresa</th>
                                    <th className="px-3 py-2.5 text-left font-black">Plan</th>
                                    <th className="px-3 py-2.5 text-right font-black">Tramo</th>
                                    <th className="px-3 py-2.5 text-right font-black">Reales</th>
                                    <th className="px-5 py-2.5 text-right font-black">USD/mes</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {cobrar.map(f => (
                                    <tr key={f.id}>
                                        <td className="px-5 py-2.5 text-foreground">
                                            {f.empresa}
                                            {f.se_paso && (
                                                <span className="ml-2 rounded bg-warning/15 px-1.5 py-0.5 text-[10px] font-bold text-warning">
                                                    pasado del tramo
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 text-muted-foreground">{f.plan}</td>
                                        <td className="px-3 py-2.5 text-right tabular-nums text-muted-foreground">
                                            {f.tramo ? f.tramo.toLocaleString('es-CO') : '—'}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums text-muted-foreground">
                                            {f.contactos_reales.toLocaleString('es-CO')}
                                        </td>
                                        <td className="px-5 py-2.5 text-right font-semibold tabular-nums text-foreground">
                                            {f.usd ? `$${f.usd}` : 'a cotizar'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="border-t-2 border-border">
                                <tr>
                                    <td colSpan={4} className="px-5 py-3 text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                        {cobrar.length} empresas
                                    </td>
                                    <td className="px-5 py-3 text-right text-base font-bold tabular-nums text-foreground">
                                        ${total_usd.toLocaleString('es-CO')}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {sin_tramo > 0 && (
                        <p className="border-t border-border bg-warning/5 px-5 py-2.5 text-xs text-foreground">
                            {sin_tramo} sin tramo asignado: salen como «a cotizar» y no suman al total.
                            Desaparecer sería peor — así es como se deja de cobrarle a alguien un año sin notarlo.
                        </p>
                    )}
                </>
            )}

            {Object.keys(porMotivo).length > 0 && (
                <div className="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-border px-5 py-3">
                    <span className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60">
                        Fuera de facturación
                    </span>
                    {Object.entries(porMotivo).map(([motivo, cuantas]) => (
                        <span key={motivo} className="text-xs text-muted-foreground">
                            <span className="font-semibold tabular-nums text-foreground">{cuantas}</span>
                            {' '}{MOTIVO_FUERA[motivo] ?? motivo}
                        </span>
                    ))}
                </div>
            )}
        </section>
    );
}

const MOTIVO_FUERA = {
    interna: 'internas nuestras',
    integra: 'con el CRM dentro de Integra',
    cortesia: 'en cortesía',
    prueba: 'en prueba',
    mes_gratis: 'con mes gratis',
};

const ETIQUETA_COBRO = {
    integra: 'Integra — el CRM va en su ERP',
    cortesia: 'Cortesía — no se factura',
    prueba: 'Prueba',
    activo: 'Activo — se factura',
    suspendido: 'Suspendido — no ha pagado',
};

/**
 * El plan de una empresa, de un vistazo en la lista.
 *
 * Lo que se quiere ver desde el panel sin abrir nada es quién está pagando y
 * quién no: con quince clientes en cortesía, esa es la única columna que
 * importa durante la transición.
 */
function PastillaDePlan({ resumen, uso }) {
    if (!resumen) return null;

    const facturando = resumen.se_factura;
    const gracia = resumen.en_mes_gratis;

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <span className="rounded-md border border-border bg-muted px-1.5 py-0.5 text-xs font-medium text-foreground">
                {resumen.plan_nombre}
            </span>
            <span className={`text-xs ${
                gracia ? 'text-warning' : facturando ? 'text-success' : 'text-muted-foreground'
            }`}>
                {gracia ? 'mes gratis' : facturando ? 'facturando' : resumen.cobro}
            </span>
            {resumen.se_paso_del_tramo && (
                <span className="text-[9px] font-bold uppercase tracking-wider text-warning">
                    +contactos
                </span>
            )}
            {uso?.exceso > 0 && (
                <span className="rounded-md bg-warning/10 px-1.5 py-0.5 text-xs text-warning">
                    +{uso.exceso} IA
                </span>
            )}
        </div>
    );
}

function Selector({ label, value, onChange, options, ayuda = '' }) {
    return (
        <div className="space-y-1.5">
            <label className="text-xs font-semibold text-foreground">{label}</label>
            <select
                value={value}
                onChange={e => onChange(e.target.value)}
                className="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none transition-colors focus:border-ring focus:ring-2 focus:ring-ring/20"
            >
                {options.map(o => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                ))}
            </select>
            {ayuda && <p className="text-[11px] leading-snug text-muted-foreground">{ayuda}</p>}
        </div>
    );
}

function Field({ label, value, onChange, type = 'text', required = false, placeholder = '', ayuda = '', autoComplete }) {
    return (
        <div className="space-y-1.5">
            <label className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                {label}
                {!required && (
                    <span className="text-[10px] font-normal text-muted-foreground">(opcional)</span>
                )}
            </label>
            <input
                type={type}
                value={value}
                onChange={e => onChange(e.target.value)}
                required={required}
                placeholder={placeholder}
                autoComplete={autoComplete}
                className="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground/60 focus:border-ring focus:ring-2 focus:ring-ring/20"
            />
            {ayuda && <p className="text-[11px] leading-snug text-muted-foreground">{ayuda}</p>}
        </div>
    );
}

MasterIndex.layout = page => <AppLayout>{page}</AppLayout>;
