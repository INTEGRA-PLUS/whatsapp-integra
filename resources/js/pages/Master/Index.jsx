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
} from 'lucide-react';

export default function MasterIndex({ stats, companies_growth, messages_volume, top_companies, companies, company_users, filters, planes = [], cobros = [], planes_resumen }) {
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
    const LineChart = ({ data, color = "#6366f1", height = 180 }) => {
        if (!data || data.length === 0) return <div className="h-full flex items-center justify-center text-xs text-muted-foreground italic">Sin datos en este rango</div>;
        const countData = data.length === 1 ? [{...data[0], x: 0}, {...data[0], x: 100}] : data;
        const max = Math.max(...countData.map(d => Number(d.count)), 2);
        const width = 1000;
        const points = countData.map((d, i) => {
            const x = (i / (countData.length > 1 ? countData.length - 1 : 1)) * width;
            const y = height - ((Number(d.count) / max) * (height * 0.8) + (height * 0.1));
            return `${x},${y}`;
        }).join(' ');
        return (
            <div className="relative w-full overflow-visible group/chart">
                <svg viewBox={`0 0 ${width} ${height}`} className="w-full h-full overflow-visible drop-shadow-sm" preserveAspectRatio="none">
                    <path d={`M ${points}`} fill="none" stroke={color} strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" className="transition-all duration-700" />
                    <path d={`M 0,${height} L ${points} L ${width},${height} Z`} fill={`url(#line-grad-${color.replace('#', '')})`} className="opacity-10 group-hover/chart:opacity-20 transition-opacity" />
                    <defs>
                        <linearGradient id={`line-grad-${color.replace('#', '')}`} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={color} /><stop offset="100%" stopColor={color} stopOpacity="0" /></linearGradient>
                    </defs>
                </svg>
            </div>
        );
    };

    const BarChart = ({ data, height = 240 }) => {
        const [activeIdx, setActiveIdx] = useState(null);
        if (!data || data.length === 0) return <div className="h-full flex items-center justify-center text-xs text-muted-foreground italic">Sin actividad reportada</div>;
        
        const max = Math.max(...data.map(d => Math.max(d.inbound, d.outbound)), 5);
        const totalMaxInPeriod = Math.max(...data.map(d => d.inbound + d.outbound), 1);

        return (
            <div className="relative h-full w-full pt-12 group/chart-container">
                {/* Single Floating Tooltip */}
                {activeIdx !== null && (
                    <div 
                        className="absolute top-0 z-30 transition-all duration-75 pointer-events-none"
                        style={{ left: `${(activeIdx / (data.length - 1)) * 100}%`, transform: 'translateX(-50%)' }}
                    >
                        <div className="bg-card border-2 border-primary/20 px-4 py-3 rounded-2xl shadow-[0_20px_50px_rgba(0,0,0,0.15)] flex flex-col items-center gap-1 min-w-[120px] animate-in zoom-in-95 duration-200">
                            <p className="text-[10px] font-black text-muted-foreground uppercase tracking-widest border-b border-border/40 pb-1 mb-1 w-full text-center">{data[activeIdx].date}</p>
                            <div className="flex gap-4">
                                <div className="flex flex-col items-center">
                                    <span className="text-[8px] font-black text-accent-foreground/60 uppercase">Inbound</span>
                                    <span className="text-sm font-black text-accent-foreground">{data[activeIdx].inbound.toLocaleString()}</span>
                                </div>
                                <div className="flex flex-col items-center">
                                    <span className="text-[8px] font-black text-success/60 uppercase">Outbound</span>
                                    <span className="text-sm font-black text-success">{data[activeIdx].outbound.toLocaleString()}</span>
                                </div>
                            </div>
                        </div>
                        {/* Guideline */}
                        <div className="w-px h-64 bg-primary/10 absolute top-12 left-1/2 -translate-x-1/2 -z-10" />
                    </div>
                )}

                <div className="flex items-end gap-1 sm:gap-2 h-full w-full">
                    {data.map((d, i) => (
                        <div 
                            key={i} 
                            onMouseEnter={() => setActiveIdx(i)}
                            onMouseLeave={() => setActiveIdx(null)}
                            className={`flex-1 flex flex-col items-center gap-1 relative h-full justify-end cursor-pointer group transition-all duration-300 ${activeIdx !== null && activeIdx !== i ? 'opacity-30 scale-x-95' : 'opacity-100'}`}
                        >
                            <div className="flex gap-0.5 w-full items-end justify-center h-full">
                                <div style={{ height: `${(d.inbound / max) * 100}%` }} className="w-1.5 sm:w-3 bg-primary/80 rounded-t-sm transition-all group-hover:bg-primary group-hover:scale-y-105 origin-bottom" />
                                <div style={{ height: `${(d.outbound / max) * 100}%` }} className="w-1.5 sm:w-3 bg-success/80 rounded-t-sm transition-all group-hover:bg-success group-hover:scale-y-105 origin-bottom" />
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        );
    };

    return (
        <>
            <Head title="Panel Master Ultra" />
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

                    {/* Dashboard Analytics Tab */}
                    {activeTab === 'dashboard' && (
                        <>
                            <div className="bg-card border border-border/40 rounded-3xl p-6 shadow-sm flex flex-col md:flex-row items-center justify-between gap-6 animate-in slide-in-from-top-4 duration-500">
                                <div className="flex items-center gap-4">
                                    <div className="size-12 rounded-2xl bg-primary/15 flex items-center justify-center text-accent-foreground shadow-inner">
                                        <Calendar className="size-6" />
                                    </div>
                                    <div>
                                        <h3 className="font-black text-lg">Reporte de Inteligencia</h3>
                                        <p className="text-sm font-medium text-muted-foreground">Analizando datos del {filters.start_date} al {filters.end_date}</p>
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center gap-3 bg-muted/20 p-2 rounded-2xl border border-border/40">
                                    <select value={range} onChange={handleRangeChange} className="h-11 px-4 rounded-xl bg-background border border-border/40 font-bold text-xs uppercase tracking-widest focus:ring-4 focus:ring-primary/10 transition-all outline-none">
                                        <option value="week">Última Semana</option>
                                        <option value="month">Último Mes</option>
                                        <option value="year">Último Año</option>
                                        <option value="custom">Rango Personalizado</option>
                                    </select>
                                    {range === 'custom' && (
                                        <div className="flex items-center gap-2">
                                            <input type="date" value={startDate} onChange={e => setStartDate(e.target.value)} className="h-11 px-4 rounded-xl bg-background border border-border/40 font-bold text-xs" />
                                            <span className="text-muted-foreground font-bold">al</span>
                                            <input type="date" value={endDate} onChange={e => setEndDate(e.target.value)} className="h-11 px-4 rounded-xl bg-background border border-border/40 font-bold text-xs" />
                                            <Button onClick={() => applyFilters()} size="icon" className="h-11 w-11 rounded-xl bg-primary hover:bg-primary"><SearchCheck className="size-5" /></Button>
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                                <KPICard label="Empresas Totales" value={stats.total_companies} sub={`Activas: ${stats.active_companies}`} icon={<Briefcase className="size-5" />} color="indigo" />
                                <KPICard label="Instancias Meta" value={stats.total_instances} sub={`Online: ${stats.active_instances}`} icon={<Layers className="size-5" />} color="blue" />
                                <KPICard label="Tráfico en Rango" value={stats.total_messages_range.toLocaleString()} trend="+12%" icon={<MessageSquare className="size-5" />} color="emerald" sub="Mensajes procesados" />
                                <KPICard label="Métricas Globales" value={stats.total_users} icon={<Users className="size-5" />} color="purple" sub="Usuarios del sistema" />
                            </div>
                            <div className="grid grid-cols-1 lg:grid-cols-12 gap-10">
                                <div className="lg:col-span-8 bg-card border border-border/40 rounded-[2.5rem] p-10 shadow-sm relative group">
                                    <div className="flex flex-col md:flex-row items-center justify-between mb-12">
                                        <h3 className="text-2xl font-black tracking-tight">Volumen de Actividad</h3>
                                        <div className="flex gap-6 mt-4 md:mt-0 bg-muted/30 px-5 py-2.5 rounded-2xl border border-border/40">
                                            <div className="flex items-center gap-2 text-xs font-black text-accent-foreground uppercase tracking-widest"><span className="size-3 rounded-full bg-primary shadow-[0_0_10px_rgba(99,102,241,0.5)]" /> Inbound</div>
                                            <div className="flex items-center gap-2 text-xs font-black text-success uppercase tracking-widest"><span className="size-3 rounded-full bg-success shadow-[0_0_10px_rgba(16,185,129,0.5)]" /> Outbound</div>
                                        </div>
                                    </div>
                                    <div className="h-80"><BarChart data={cleanVolume} height={280} /></div>
                                    <div className="mt-8 flex flex-wrap gap-4 items-center justify-between bg-muted/10 p-6 rounded-3xl border border-border/10">
                                        <StatLabel label="PICO MÁXIMO" value={Math.max(...cleanVolume.map(m => m.inbound + m.outbound), 0).toLocaleString()} border />
                                        <StatLabel label="PROMEDIO DIARIO" value={Math.round(cleanVolume.reduce((a, b) => a + (b.inbound + b.outbound), 0) / (cleanVolume.length || 1)).toLocaleString()} border />
                                        <StatLabel label="TOTAL PERIODO" value={cleanVolume.reduce((a, b) => a + (b.inbound + b.outbound), 0).toLocaleString()} color="text-accent-foreground" />
                                    </div>
                                </div>
                                <div className="lg:col-span-4 bg-card border border-border/40 rounded-[2.5rem] p-10 shadow-sm relative group">
                                    <div className="flex items-center justify-between mb-10">
                                        <h3 className="text-xl font-black tracking-tight">Top Clientes</h3>
                                        <div className="size-12 rounded-2xl bg-primary/15 flex items-center justify-center text-accent-foreground"><TrendingUp className="size-6" /></div>
                                    </div>
                                    <div className="space-y-8">
                                        {top_companies.map((co) => (
                                            <div key={co.id} className="group/item">
                                                <div className="flex items-center justify-between mb-2.5">
                                                    <div className="flex items-center gap-4">
                                                        <div className="size-10 rounded-2xl bg-primary/10 flex items-center justify-center text-accent-foreground font-black text-sm">{co.name.charAt(0)}</div>
                                                        <span className="text-sm font-black truncate max-w-[120px]">{co.name}</span>
                                                    </div>
                                                    <span className="text-sm font-black text-foreground">{Number(co.messages_count || 0).toLocaleString()} <span className="text-[8px] opacity-40 uppercase ml-1">MSG</span></span>
                                                </div>
                                                <div className="w-full h-1.5 bg-muted rounded-full overflow-hidden"><div className="h-full bg-primary rounded-full" style={{ width: `${Math.max((Number(co.messages_count || 1) / (Number(top_companies[0].messages_count || 1))) * 100, 2)}%` }} /></div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            {/* Network Growth */}
                            <div className="bg-card border border-border/40 rounded-[2.5rem] p-10 shadow-sm overflow-hidden group">
                                <div className="flex flex-col md:flex-row items-center justify-between mb-10">
                                    <div>
                                        <h3 className="text-2xl font-black tracking-tight mb-2 uppercase">Crecimiento de RED</h3>
                                        <p className="text-sm font-medium text-muted-foreground">Histórico de expansión y nuevas integraciones corporativas</p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <div className="size-10 rounded-2xl bg-primary/10 flex items-center justify-center text-accent-foreground"><TrendingUp className="size-5" /></div>
                                        <span className="text-[11px] font-black text-muted-foreground uppercase tracking-widest">Global +{companies_growth.reduce((a, b) => a + Number(b.count), 0)}</span>
                                    </div>
                                </div>
                                <div className="h-44 px-4"><LineChart data={companies_growth} color="#6366f1" height={160} /></div>
                            </div>
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
                            <div className="grid gap-4 md:grid-cols-3">
                                {planes_resumen.planes.map(plan => (
                                    <section key={plan.slug} className="flex flex-col rounded-xl border border-border bg-card p-5">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <h3 className="font-heading text-base font-semibold text-foreground">{plan.nombre}</h3>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {plan.ia
                                                        ? `Desde ${plan.ia.toLocaleString('es-CO')} conversaciones con IA al mes`
                                                        : 'Sin IA'}
                                                </p>
                                            </div>
                                            <span className="shrink-0 rounded-md border border-border bg-muted px-2 py-0.5 text-xs tabular-nums text-muted-foreground">
                                                {plan.empresas} {plan.empresas === 1 ? 'empresa' : 'empresas'}
                                            </span>
                                        </div>

                                        <div className="mt-4 border-y border-border py-3">
                                            {plan.precio_usd === null ? (
                                                <p className="text-sm text-muted-foreground">Precio sin definir</p>
                                            ) : (
                                                <p className="flex items-baseline gap-1.5">
                                                    <span className="text-2xl font-semibold tabular-nums text-foreground">
                                                        ${plan.precio_usd}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">USD al mes</span>
                                                </p>
                                            )}
                                            <p className="mt-1 text-xs tabular-nums text-muted-foreground">
                                                {plan.facturando} facturando
                                            </p>
                                        </div>

                                        <p className="mt-4 text-xs font-medium text-foreground">
                                            {plan.todas_las_extensiones
                                                ? 'Todas las extensiones'
                                                : `${plan.extensiones.length} extensiones`}
                                        </p>
                                        <ul className="mt-2 space-y-1.5">
                                            {plan.extensiones.map(nombre => (
                                                <li key={nombre} className="flex items-start gap-2 text-xs text-muted-foreground">
                                                    <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-muted-foreground/60" />
                                                    {nombre}
                                                </li>
                                            ))}
                                        </ul>
                                    </section>
                                ))}
                            </div>

                            <div className="grid gap-6 lg:grid-cols-2">
                                <section className="overflow-hidden rounded-xl border border-border bg-card">
                                    <div className="border-b border-border px-5 py-4">
                                        <h3 className="font-heading text-sm font-semibold text-foreground">
                                            Crédito de IA por tramo de contactos
                                        </h3>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            El tramo contratado decide las conversaciones incluidas. Al agotarse no se
                                            corta nada: se factura el exceso.
                                        </p>
                                    </div>
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-border bg-muted/40 text-xs text-muted-foreground">
                                                <th className="px-5 py-2.5 text-left font-medium">Hasta</th>
                                                <th className="px-5 py-2.5 text-right font-medium">Conversaciones con IA</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {planes_resumen.tramos.map(tramo => (
                                                <tr key={tramo.hasta} className="border-b border-border/60 last:border-0">
                                                    <td className="px-5 py-2.5 tabular-nums text-foreground">
                                                        {tramo.hasta.toLocaleString('es-CO')} contactos
                                                    </td>
                                                    <td className="px-5 py-2.5 text-right tabular-nums text-muted-foreground">
                                                        {tramo.ia.toLocaleString('es-CO')}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </section>

                                <section className="rounded-xl border border-border bg-card">
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
                            </div>

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

                        <Selector
                            label="Cobro"
                            value={planForm.cobro}
                            onChange={v => setPlanForm({ ...planForm, cobro: v })}
                            options={cobros.map(c => ({ value: c, label: ETIQUETA_COBRO[c] ?? c }))}
                            ayuda="Cortesía es el estado de los clientes de siempre: todo encendido y sin factura. Suspendido no apaga nada, solo marca."
                        />

                        <Field
                            label="Socios o contactos contratados"
                            type="number"
                            value={planForm.contactos_contratados}
                            onChange={v => setPlanForm({ ...planForm, contactos_contratados: v })}
                            placeholder="12000"
                            ayuda="El tramo de la escalera de precios. Decide el crédito de IA incluido. No es un límite: nadie deja de atender por crecer."
                        />

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

function StatLabel({ label, value, border, color = "text-foreground" }) {
    return (
        <div className={`text-center flex-1 px-4 ${border ? 'border-r border-border/40' : ''}`}>
            <p className="text-[10px] font-black text-muted-foreground uppercase tracking-widest mb-1.5 opacity-60">{label}</p>
            <p className={`font-black text-xl tracking-tight ${color}`}>{value}</p>
        </div>
    );
}

function KPICard({ label, value, sub, icon, trend, color }) {
    const colors = { indigo: "text-accent-foreground", blue: "text-info", emerald: "text-success", purple: "text-accent-foreground" };
    return (
        <div className={`bg-card border border-border/40 rounded-[2.5rem] p-9 shadow-sm transition-all hover:shadow-2xl hover:-translate-y-2 group relative overflow-hidden`}>
            <div className="flex justify-between items-start mb-8">
                <div className={`size-14 rounded-2xl flex items-center justify-center bg-muted/30 shadow-inner ${colors[color]}`}>{icon}</div>
                {trend && <span className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black tracking-widest bg-success/10 text-success border border-success/10 animate-in fade-in duration-700">{trend}</span>}
            </div>
            <h4 className="text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em] mb-4 opacity-60">{label}</h4>
            <div className="flex flex-col gap-1.5">
                <span className="text-5xl font-black tracking-tighter leading-none text-foreground">{value}</span>
                <span className="text-[11px] font-bold text-muted-foreground opacity-60 uppercase tracking-widest mt-1">{sub}</span>
            </div>
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

const ETIQUETA_COBRO = {
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
