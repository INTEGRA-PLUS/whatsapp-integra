import { useState, useCallback, useMemo, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
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
    Zap,
    ArrowUpRight,
    ArrowDownRight,
    Calendar,
    Filter,
    Download,
    ChevronRight,
    SearchCheck,
    Package,
    Rocket,
    Crown,
    ShieldCheck,
    BarChart as BarChartIcon
} from 'lucide-react';

export default function MasterIndex({ stats, companies_growth, messages_volume, top_companies, companies, filters }) {
    const [activeTab, setActiveTab] = useState('dashboard');
    const [showCreate, setShowCreate] = useState(false);
    const [editingCompany, setEditingCompany] = useState(null);
    
    // Sync tab with URL
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab');
        if (tab && ['dashboard', 'companies', 'plans'].includes(tab)) {
            setActiveTab(tab);
        }
    }, [filters]);

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

    const applyFilters = useCallback((params) => {
        router.get(route('master.index'), { 
            search: search, 
            status: status, 
            range: range,
            start_date: startDate,
            end_date: endDate,
            ...params 
        }, { preserveState: true, replace: true });
    }, [search, status, range, startDate, endDate]);

    function handleSearchChange(e) {
        const val = e.target.value;
        setSearch(val);
        clearTimeout(window._st);
        window._st = setTimeout(() => applyFilters({ search: val }), 500);
    }

    function handleRangeChange(e) {
        const val = e.target.value;
        setRange(val);
        applyFilters({ range: val });
    }

    const { data: list, links } = companies;

    function handleCreate(e) {
        e.preventDefault();
        router.post(route('master.companies.store'), createForm, { onSuccess: () => { setShowCreate(false); setCreateForm({ name: '', email: '', admin_name: '', admin_email: '', password: '' }); } });
    }

    function handleEdit(e) {
        e.preventDefault();
        router.put(route('master.companies.update', editingCompany.id), editForm, { onSuccess: () => setEditingCompany(null) });
    }

    function openEdit(company) {
        const admin = company.users?.[0];
        setEditForm({ name: company.name, email: company.email, admin_name: admin?.name ?? '', admin_email: admin?.email ?? '', password: '', active: !!company.active });
        setEditingCompany(company);
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
                        <div className="bg-card border-2 border-indigo-500/20 px-4 py-3 rounded-2xl shadow-[0_20px_50px_rgba(0,0,0,0.15)] flex flex-col items-center gap-1 min-w-[120px] animate-in zoom-in-95 duration-200">
                            <p className="text-[10px] font-black text-muted-foreground uppercase tracking-widest border-b border-border/40 pb-1 mb-1 w-full text-center">{data[activeIdx].date}</p>
                            <div className="flex gap-4">
                                <div className="flex flex-col items-center">
                                    <span className="text-[8px] font-black text-indigo-500/60 uppercase">Inbound</span>
                                    <span className="text-sm font-black text-indigo-600">{data[activeIdx].inbound.toLocaleString()}</span>
                                </div>
                                <div className="flex flex-col items-center">
                                    <span className="text-[8px] font-black text-emerald-500/60 uppercase">Outbound</span>
                                    <span className="text-sm font-black text-emerald-600">{data[activeIdx].outbound.toLocaleString()}</span>
                                </div>
                            </div>
                        </div>
                        {/* Guideline */}
                        <div className="w-px h-64 bg-indigo-500/10 absolute top-12 left-1/2 -translate-x-1/2 -z-10" />
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
                                <div style={{ height: `${(d.inbound / max) * 100}%` }} className="w-1.5 sm:w-3 bg-indigo-500/80 rounded-t-sm transition-all group-hover:bg-indigo-600 group-hover:scale-y-105 origin-bottom" />
                                <div style={{ height: `${(d.outbound / max) * 100}%` }} className="w-1.5 sm:w-3 bg-emerald-500/80 rounded-t-sm transition-all group-hover:bg-emerald-600 group-hover:scale-y-105 origin-bottom" />
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
            <div className="flex flex-col min-h-screen bg-muted/10 selection:bg-indigo-500 selection:text-white">
                
                {/* Master Header Pulido & Profesional */}
                <div className="bg-card/40 backdrop-blur-3xl px-8 py-8 sticky top-0 z-40 border-b border-border/20 shadow-sm">
                    <div className="max-w-[1700px] mx-auto flex flex-col xl:flex-row items-center justify-between gap-6">
                        <div className="flex items-center gap-6">
                            <div className="size-14 rounded-2xl bg-indigo-600 flex items-center justify-center text-white shadow-2xl shadow-indigo-600/20 transform transition-all hover:scale-105 active:scale-95 cursor-pointer group" onClick={() => setActiveTab('dashboard')}>
                                <Zap className="size-8 group-hover:animate-pulse" />
                            </div>
                            <div className="h-10 w-px bg-border/40 hidden md:block" />
                            <div>
                                <h1 className="text-2xl font-black tracking-tight text-foreground uppercase flex items-center gap-3">
                                    {activeTab === 'dashboard' ? 'Centro de Analítica' : activeTab === 'companies' ? 'Directorio de Empresas' : 'Gestión de Planes'}
                                    <span className="text-[10px] font-black bg-indigo-500/10 text-indigo-600 px-2 py-0.5 rounded-full border border-indigo-500/20 tracking-widest hidden sm:inline-block">MASTER</span>
                                </h1>
                                <div className="flex items-center gap-3 mt-1.5">
                                    <div className="flex items-center gap-2 group cursor-help">
                                        <div className="size-2 rounded-full bg-emerald-500 animate-pulse shadow-[0_0_8px_rgba(16,185,129,0.5)]"></div>
                                        <span className="text-[10px] font-black text-emerald-600/80 tracking-widest uppercase">Integra Cluster Online</span>
                                    </div>
                                    <span className="text-[10px] font-bold text-muted-foreground/30 uppercase tracking-[0.2em]">• v2.4.0 PRO</span>
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3 bg-muted/20 p-1.5 rounded-2xl border border-border/40 backdrop-blur-sm">
                            <Button variant="ghost" className="rounded-xl h-11 px-5 text-muted-foreground font-black hover:bg-card hover:text-indigo-600 uppercase tracking-widest text-[10px] gap-2 hidden lg:flex transition-all">
                                <Download className="size-4" /> Exportar Datos
                            </Button>
                            <Button variant="ghost" className="rounded-xl h-11 px-5 text-muted-foreground font-black hover:bg-card hover:text-indigo-600 uppercase tracking-widest text-[10px] gap-2 hidden lg:flex transition-all">
                                <Search className="size-4" /> Búsqueda Global
                            </Button>
                            <div className="w-px h-6 bg-border/40 mx-2 hidden lg:block" />
                            <Button onClick={() => setShowCreate(true)} className="rounded-xl h-11 px-8 font-black uppercase tracking-widest text-[10px] shadow-lg shadow-indigo-500/10 bg-indigo-600 hover:bg-indigo-700 text-white gap-2 transition-all active:scale-95">
                                <Plus className="size-4" /> Nueva Organización
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="p-8 max-w-[1700px] mx-auto w-full space-y-10">

                    {/* Dashboard Analytics Tab */}
                    {activeTab === 'dashboard' && (
                        <>
                            <div className="bg-card border border-border/40 rounded-3xl p-6 shadow-sm flex flex-col md:flex-row items-center justify-between gap-6 animate-in slide-in-from-top-4 duration-500">
                                <div className="flex items-center gap-4">
                                    <div className="size-12 rounded-2xl bg-indigo-100 flex items-center justify-center text-indigo-600 shadow-inner">
                                        <Calendar className="size-6" />
                                    </div>
                                    <div>
                                        <h3 className="font-black text-lg">Reporte de Inteligencia</h3>
                                        <p className="text-sm font-medium text-muted-foreground">Analizando datos del {filters.start_date} al {filters.end_date}</p>
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center gap-3 bg-muted/20 p-2 rounded-2xl border border-border/40">
                                    <select value={range} onChange={handleRangeChange} className="h-11 px-4 rounded-xl bg-background border border-border/40 font-bold text-xs uppercase tracking-widest focus:ring-4 focus:ring-indigo-500/10 transition-all outline-none">
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
                                            <Button onClick={() => applyFilters()} size="icon" className="h-11 w-11 rounded-xl bg-indigo-600 hover:bg-indigo-700"><SearchCheck className="size-5" /></Button>
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
                                            <div className="flex items-center gap-2 text-xs font-black text-indigo-500 uppercase tracking-widest"><span className="size-3 rounded-full bg-indigo-500 shadow-[0_0_10px_rgba(99,102,241,0.5)]" /> Inbound</div>
                                            <div className="flex items-center gap-2 text-xs font-black text-emerald-500 uppercase tracking-widest"><span className="size-3 rounded-full bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.5)]" /> Outbound</div>
                                        </div>
                                    </div>
                                    <div className="h-80"><BarChart data={cleanVolume} height={280} /></div>
                                    <div className="mt-8 flex flex-wrap gap-4 items-center justify-between bg-muted/10 p-6 rounded-3xl border border-border/10">
                                        <StatLabel label="PICO MÁXIMO" value={Math.max(...cleanVolume.map(m => m.inbound + m.outbound), 0).toLocaleString()} border />
                                        <StatLabel label="PROMEDIO DIARIO" value={Math.round(cleanVolume.reduce((a, b) => a + (b.inbound + b.outbound), 0) / (cleanVolume.length || 1)).toLocaleString()} border />
                                        <StatLabel label="TOTAL PERIODO" value={cleanVolume.reduce((a, b) => a + (b.inbound + b.outbound), 0).toLocaleString()} color="text-indigo-600" />
                                    </div>
                                </div>
                                <div className="lg:col-span-4 bg-card border border-border/40 rounded-[2.5rem] p-10 shadow-sm relative group">
                                    <div className="flex items-center justify-between mb-10">
                                        <h3 className="text-xl font-black tracking-tight">Top Clientes</h3>
                                        <div className="size-12 rounded-2xl bg-indigo-50 flex items-center justify-center text-indigo-600"><TrendingUp className="size-6" /></div>
                                    </div>
                                    <div className="space-y-8">
                                        {top_companies.map((co) => (
                                            <div key={co.id} className="group/item">
                                                <div className="flex items-center justify-between mb-2.5">
                                                    <div className="flex items-center gap-4">
                                                        <div className="size-10 rounded-2xl bg-indigo-500/10 flex items-center justify-center text-indigo-700 font-black text-sm">{co.name.charAt(0)}</div>
                                                        <span className="text-sm font-black truncate max-w-[120px]">{co.name}</span>
                                                    </div>
                                                    <span className="text-sm font-black text-foreground">{Number(co.messages_count || 0).toLocaleString()} <span className="text-[8px] opacity-40 uppercase ml-1">MSG</span></span>
                                                </div>
                                                <div className="w-full h-1.5 bg-muted rounded-full overflow-hidden"><div className="h-full bg-indigo-500 rounded-full" style={{ width: `${Math.max((Number(co.messages_count || 1) / (Number(top_companies[0].messages_count || 1))) * 100, 2)}%` }} /></div>
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
                                        <div className="size-10 rounded-2xl bg-indigo-600/10 flex items-center justify-center text-indigo-600"><TrendingUp className="size-5" /></div>
                                        <span className="text-[11px] font-black text-muted-foreground uppercase tracking-widest">Global +{companies_growth.reduce((a, b) => a + Number(b.count), 0)}</span>
                                    </div>
                                </div>
                                <div className="h-44 px-4"><LineChart data={companies_growth} color="#6366f1" height={160} /></div>
                            </div>
                        </>
                    )}

                    {/* Companies Tab */}
                    {activeTab === 'companies' && (
                        <div className="bg-card border border-border/40 rounded-[2.5rem] shadow-xl overflow-hidden animate-in slide-in-from-bottom-8 duration-700">
                            <div className="p-10 border-b bg-muted/20">
                                <div className="flex flex-col lg:flex-row gap-6">
                                    <div className="relative flex-1 group">
                                        <Search className="absolute left-5 top-1/2 -translate-y-1/2 size-6 text-muted-foreground group-focus-within:text-indigo-500 transition-all" />
                                        <input type="text" placeholder="Auditar empresas, emails o administradores activos..." value={search} onChange={handleSearchChange} className="w-full h-14 pl-14 pr-6 bg-background border border-border/40 focus:border-indigo-500/60 rounded-2xl text-base transition-all focus:ring-8 focus:ring-indigo-500/5 outline-none font-medium shadow-inner" />
                                    </div>
                                    <div className="flex gap-4">
                                        <select value={status} onChange={e => { setStatus(e.target.value); applyFilters({ status: e.target.value }); }} className="h-14 rounded-2xl border border-border/40 bg-background px-8 text-sm outline-none font-black uppercase tracking-widest cursor-pointer hover:border-indigo-500/40 transition-all shadow-sm">
                                            <option value="">Todos los Estados</option>
                                            <option value="active">Activas</option>
                                            <option value="inactive">Inactivas</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full">
                                    <thead>
                                        <tr className="bg-muted/30 border-b border-border/40">
                                            <th className="px-10 py-6 text-left text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em]">Entidad / Despliegue</th>
                                            <th className="px-10 py-6 text-left text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em] hidden md:table-cell">Admin</th>
                                            <th className="px-10 py-6 text-center text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em]">Estado</th>
                                            <th className="px-10 py-6 text-right text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em]">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border/30">
                                        {list.map(company => (
                                            <tr key={company.id} className="hover:bg-indigo-500/[0.02] transition-colors group">
                                                <td className="px-10 py-7">
                                                    <div className="flex items-center gap-5">
                                                        <div className="size-14 rounded-[1.25rem] bg-indigo-500/5 border border-indigo-500/20 flex items-center justify-center text-indigo-700 font-black text-xl group-hover:bg-indigo-600 group-hover:text-white transition-all duration-300 shadow-sm">{company.name.charAt(0)}</div>
                                                        <div className="min-w-0"><p className="font-bold text-lg text-foreground truncate group-hover:text-indigo-600 transition-colors uppercase tracking-tight">{company.name}</p><p className="text-xs font-bold text-muted-foreground/60">{company.email}</p></div>
                                                    </div>
                                                </td>
                                                <td className="px-10 py-7 hidden md:table-cell">
                                                    {company.users?.[0] ? <div className="text-sm font-black text-foreground">{company.users[0].name}</div> : <span className="text-xs opacity-40">Sin Admin</span>}
                                                </td>
                                                <td className="px-10 py-7 text-center">
                                                    <span className={`inline-flex px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-[0.2em] shadow-sm ${company.active ? 'bg-emerald-500/10 text-emerald-600 border border-emerald-500/20' : 'bg-muted text-muted-foreground border border-border/40'}`}>{company.active ? 'Activa' : 'Inactiva'}</span>
                                                </td>
                                                <td className="px-10 py-7">
                                                    <div className="flex items-center justify-end gap-3 opacity-20 group-hover:opacity-100 transition-all duration-300">
                                                        <Button variant="ghost" size="icon" onClick={() => openEdit(company)} className="size-11 rounded-xl bg-muted/40 hover:bg-indigo-600 hover:text-white transition-all"><Pencil className="size-5" /></Button>
                                                        <Button onClick={() => router.post(route('master.impersonate', company.id))} className="h-11 px-6 rounded-xl bg-indigo-600 text-white font-black text-[10px] uppercase tracking-widest shadow-xl shadow-indigo-600/20 hover:bg-indigo-700 transition-all"><LogIn className="size-4 mr-2" /> Entrar</Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Plans Tab */}
                    {activeTab === 'plans' && (
                        <div className="bg-card border border-border/40 rounded-[2.5rem] shadow-xl overflow-hidden animate-in slide-in-from-right-8 duration-700">
                            <div className="p-10 border-b bg-muted/20 flex justify-between items-center">
                                <div>
                                    <h3 className="text-2xl font-black tracking-tight uppercase">Gestión de Planes</h3>
                                    <p className="text-sm font-medium text-muted-foreground mt-1 text-xs uppercase tracking-widest opacity-60">Configuración comercial y suscripciones corporativas</p>
                                </div>
                                <Button className="rounded-2xl h-12 px-6 font-black uppercase tracking-widest text-[11px] bg-indigo-600 shadow-xl shadow-indigo-600/20">
                                    <Plus className="size-4 mr-2" /> Nuevo Plan
                                </Button>
                            </div>
                            <div className="p-10">
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                                    <PlanCard icon={<Rocket className="size-10 text-blue-600" />} name="Startup" price="49.99" color="blue" features={["5 Instancias", "Usuarios Ilimitados", "Reportes Básicos"]} />
                                    <PlanCard icon={<Crown className="size-10 text-indigo-600" />} name="Business Pro" price="129.99" color="indigo" features={["20 Instancias", "Soporte Prioritario", "API Access", "Reportes Avanzados"]} popular />
                                    <PlanCard icon={<ShieldCheck className="size-10 text-emerald-600" />} name="Enterprise" price="499.99" color="emerald" features={["Instancias Ilimitadas", "Dedicated Manager", "SLA 99.99%", "Custom Integrations"]} />
                                </div>
                                <div className="mt-12 overflow-x-auto rounded-[2rem] border border-border/20 shadow-inner">
                                    <table className="w-full">
                                        <thead>
                                            <tr className="bg-muted/30 border-b border-border/20">
                                                <th className="px-8 py-5 text-left text-[10px] font-black text-muted-foreground uppercase tracking-widest opacity-40">Plan</th>
                                                <th className="px-8 py-5 text-center text-[10px] font-black text-muted-foreground uppercase tracking-widest opacity-40">Suscripciones</th>
                                                <th className="px-8 py-5 text-center text-[10px] font-black text-muted-foreground uppercase tracking-widest opacity-40">Costo Mensual</th>
                                                <th className="px-8 py-5 text-right text-[10px] font-black text-muted-foreground uppercase tracking-widest opacity-40">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-border/10">
                                            {[
                                                { name: 'Startup', subs: 12, cost: '$49.99' },
                                                { name: 'Business Pro', subs: 45, cost: '$129.99' },
                                                { name: 'Enterprise', subs: 4, cost: '$499.99' }
                                            ].map((plan, i) => (
                                                <tr key={i} className="hover:bg-muted/10 transition-colors">
                                                    <td className="px-8 py-6 font-black text-foreground uppercase tracking-tight">{plan.name}</td>
                                                    <td className="px-8 py-6 text-center font-bold">{plan.subs}</td>
                                                    <td className="px-8 py-6 text-center font-black text-indigo-600">{plan.cost}</td>
                                                    <td className="px-8 py-6 text-right">
                                                        <Button variant="ghost" size="sm" className="font-black text-[10px] uppercase tracking-widest hover:bg-indigo-50">Configurar</Button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
                
                <div className="mt-auto py-12 text-center">
                    <p className="text-[10px] font-black text-muted-foreground/30 uppercase tracking-[0.5em]">Integra Plus Corporate · WhatsApp Management Engine</p>
                </div>
            </div>

            {/* Modals for Create/Edit Company */}
            {showCreate && <Modal title="Deploy New Organization" onClose={() => setShowCreate(false)}><form onSubmit={handleCreate} className="space-y-8"><Field label="Nombre Empresa" value={createForm.name} onChange={v => setCreateForm(f => ({ ...f, name: v }))} required /><Field label="Email Empresa" type="email" value={createForm.email} onChange={v => setCreateForm(f => ({ ...f, email: v }))} required /><div className="p-6 rounded-[2rem] bg-indigo-50/50 border border-indigo-500/10 space-y-6"><Field label="Nombre Admin" value={createForm.admin_name} onChange={v => setCreateForm(f => ({ ...f, admin_name: v }))} required /><Field label="Email Admin" value={createForm.admin_email} onChange={v => setCreateForm(f => ({ ...f, admin_email: v }))} required /><Field label="Password" type="password" value={createForm.password} onChange={v => setCreateForm(f => ({ ...f, password: v }))} required /></div><Button type="submit" className="w-full h-14 rounded-2xl bg-indigo-600 font-black uppercase tracking-widest text-white shadow-xl shadow-indigo-600/20">Ejecutar Deployment</Button></form></Modal>}
            {editingCompany && <Modal title="Edit Organization" onClose={() => setEditingCompany(null)}><form onSubmit={handleEdit} className="space-y-8"><Field label="Nombre" value={editForm.name} onChange={v => setEditForm(f => ({ ...f, name: v }))} required /><Field label="Email" type="email" value={editForm.email} onChange={v => setEditForm(f => ({ ...f, email: v }))} required /><div className="p-6 rounded-[2rem] bg-indigo-50/50 border border-indigo-500/10 space-y-6"><Field label="Admin" value={editForm.admin_name} onChange={v => setEditForm(f => ({ ...f, admin_name: v }))} required /><Field label="Email Admin" type="email" value={editForm.admin_email} onChange={v => setEditForm(f => ({ ...f, admin_email: v }))} required /><Field label="Password" type="password" value={editForm.password} onChange={v => setEditForm(f => ({ ...f, password: v }))} /></div><Button type="submit" className="w-full h-14 rounded-2xl bg-indigo-600 font-black uppercase tracking-widest text-white shadow-xl shadow-indigo-600/20">Guardar Cambios</Button></form></Modal>}
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

function PlanCard({ icon, name, price, color, features, popular }) {
    return (
        <div className={`relative p-8 rounded-[2.5rem] border transition-all duration-500 hover:-translate-y-3 ${popular ? 'border-indigo-500 bg-indigo-500/[0.03] scale-105 shadow-2xl shadow-indigo-500/10' : 'border-border/40 bg-card'} overflow-hidden`}>
            {popular && <div className="absolute top-0 right-0 bg-indigo-500 text-white px-6 py-2 rounded-bl-[2rem] text-[10px] font-black uppercase tracking-widest shadow-xl">Más Seleccionado</div>}
            <div className="mb-6 transform transition-transform group-hover:scale-110">{icon}</div>
            <h4 className="text-xl font-black tracking-tight mb-2 uppercase">{name}</h4>
            <div className="flex items-baseline gap-1 mb-8">
                <span className="text-3xl font-black tracking-tighter">${price}</span>
                <span className="text-[10px] font-black text-muted-foreground uppercase tracking-widest">/ mes</span>
            </div>
            <ul className="space-y-4 mb-10">
                {features.map((f, i) => (
                    <li key={i} className="flex items-center gap-3 text-xs font-bold opacity-80 uppercase tracking-wide">
                        <CheckCircle2 className={`size-4 ${popular ? 'text-indigo-500' : 'text-emerald-500'}`} /> {f}
                    </li>
                ))}
            </ul>
            <Button className={`w-full rounded-2xl font-black uppercase tracking-widest text-[10px] h-12 shadow-xl transition-all active:scale-95 ${popular ? 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-600/20' : 'bg-foreground hover:bg-foreground/90 text-background'}`}>Suscripción Corporate</Button>
        </div>
    );
}

function KPICard({ label, value, sub, icon, trend, color }) {
    const colors = { indigo: "text-indigo-700", blue: "text-blue-700", emerald: "text-emerald-700", purple: "text-purple-700" };
    return (
        <div className={`bg-card border border-border/40 rounded-[2.5rem] p-9 shadow-sm transition-all hover:shadow-2xl hover:-translate-y-2 group relative overflow-hidden`}>
            <div className="flex justify-between items-start mb-8">
                <div className={`size-14 rounded-2xl flex items-center justify-center bg-muted/30 shadow-inner ${colors[color]}`}>{icon}</div>
                {trend && <span className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black tracking-widest bg-emerald-500/10 text-emerald-600 border border-emerald-500/10 animate-in fade-in duration-700">{trend}</span>}
            </div>
            <h4 className="text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em] mb-4 opacity-60">{label}</h4>
            <div className="flex flex-col gap-1.5">
                <span className="text-5xl font-black tracking-tighter leading-none text-foreground">{value}</span>
                <span className="text-[11px] font-bold text-muted-foreground opacity-60 uppercase tracking-widest mt-1">{sub}</span>
            </div>
        </div>
    );
}

function Modal({ title, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-background/60 backdrop-blur-2xl p-4 animate-in fade-in duration-300" onClick={onClose}>
            <div className="w-full max-w-xl rounded-[3rem] bg-card border border-border/40 shadow-2xl p-14 animate-in zoom-in-95 duration-500 relative overflow-hidden" onClick={e => e.stopPropagation()}>
                <div className="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-indigo-600 to-blue-500" />
                <h2 className="text-3xl font-black text-center mb-12 tracking-tight uppercase text-foreground">{title}</h2>
                {children}
            </div>
        </div>
    );
}

function Field({ label, value, onChange, type = 'text', required = false, placeholder = '' }) {
    return (
        <div className="space-y-3">
            <label className="text-[11px] font-black text-muted-foreground uppercase tracking-widest ml-1">{label}</label>
            <input type={type} value={value} onChange={e => onChange(e.target.value)} required={required} placeholder={placeholder} className="h-14 w-full rounded-2xl border border-border/40 bg-background px-6 text-sm font-bold shadow-inner outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500/40 transition-all font-mono" />
        </div>
    );
}

MasterIndex.layout = page => <AppLayout>{page}</AppLayout>;
