import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { 
    Pencil, 
    Trash2, 
    Users, 
    UserPlus, 
    Shield, 
    User as UserIcon, 
    Search,
    Filter,
    Activity,
    UserCheck,
    UserX,
    TrendingUp
} from 'lucide-react';
import { useState } from 'react';

export default function UsersIndex({ users, stats }) {
    const { auth } = usePage().props;
    const currentUser = auth.user;
    const [search, setSearch] = useState('');

    const filteredUsers = users.filter(user => 
        user.name.toLowerCase().includes(search.toLowerCase()) || 
        user.email.toLowerCase().includes(search.toLowerCase())
    );

    function handleDelete(user) {
        if (user.id === currentUser.id) {
            alert('No puedes eliminarte a ti mismo.');
            return;
        }
        if (!confirm('¿Estás seguro de que deseas eliminar este usuario? Esta acción no se puede deshacer.')) return;
        router.delete(route('users.destroy', user.id));
    }

    const StatCard = ({ title, value, icon: Icon, color }) => (
        <div className="bg-card border rounded-2xl p-5 shadow-sm hover:shadow-md transition-all flex items-center gap-4">
            <div className={`size-12 rounded-xl flex items-center justify-center ${color}`}>
                <Icon className="size-6" />
            </div>
            <div>
                <p className="text-sm text-muted-foreground font-medium">{title}</p>
                <p className="text-2xl font-bold text-foreground">{value}</p>
            </div>
        </div>
    );

    const getRoleBadge = (roles) => {
        const role = roles?.[0]?.name || 'Sin Rol';
        switch (role.toLowerCase()) {
            case 'admin':
                return <span className="inline-flex items-center gap-1 bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider border border-purple-200 dark:border-purple-800"><Shield className="size-3" /> Administrador</span>;
            case 'agent':
            case 'agente':
                return <span className="inline-flex items-center gap-1 bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider border border-blue-200 dark:border-blue-800"><UserIcon className="size-3" /> Agente</span>;
            default:
                return <span className="inline-flex items-center gap-1 bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider border border-gray-200 dark:border-gray-800">{role}</span>;
        }
    };

    return (
        <>
            <Head title="Equipo" />
            <div className="flex flex-col h-full">
                {/* Header Superior con Degradado */}
                <div className="bg-white dark:bg-black border-b relative overflow-hidden">
                    <div className="max-w-7xl mx-auto px-6 py-10 relative">
                        <div className="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                            <div>
                                <h1 className="text-4xl font-black tracking-tight text-foreground flex items-center gap-4">
                                    <div className="size-12 bg-primary text-primary-foreground rounded-2xl flex items-center justify-center rotate-3">
                                        <Users className="size-7" />
                                    </div>
                                    Mi Equipo
                                </h1>
                                <p className="text-muted-foreground mt-2 text-lg">Administra los accesos y roles de tu organización.</p>
                            </div>
                            <Button asChild size="lg" className="gap-2 shadow-xl shadow-primary/20 h-12 px-8 rounded-xl transition-all hover:scale-105 active:scale-95">
                                <Link href={route('users.create')}>
                                    <UserPlus className="size-5" /> Agregar Miembro
                                </Link>
                            </Button>
                        </div>

                        {/* Fila de Estadísticas */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <StatCard 
                                title="Total Equipo" 
                                value={stats.total} 
                                icon={Users} 
                                color="bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400" 
                            />
                            <StatCard 
                                title="Miembros Activos" 
                                value={stats.active} 
                                icon={UserCheck} 
                                color="bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400" 
                            />
                            <StatCard 
                                title="Administradores" 
                                value={stats.admins} 
                                icon={Shield} 
                                color="bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400" 
                            />
                            <StatCard 
                                title="Agentes" 
                                value={stats.agents} 
                                icon={Activity} 
                                color="bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400" 
                            />
                        </div>
                    </div>
                </div>

                {/* Filtros y Buscador */}
                <div className="px-6 py-5 border-b bg-background/50 backdrop-blur-xl sticky top-0 z-20">
                    <div className="max-w-7xl mx-auto flex flex-col sm:flex-row gap-4">
                        <div className="relative flex-1 group">
                            <Search className="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-muted-foreground group-focus-within:text-primary transition-colors" />
                            <input 
                                type="text"
                                placeholder="Buscar colaborador por nombre o email..."
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                className="w-full h-12 pl-12 pr-4 rounded-xl border bg-background text-sm focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all shadow-sm"
                            />
                        </div>
                        <Button variant="outline" size="lg" className="gap-2 shrink-0 h-12 px-6 rounded-xl border-dashed">
                            <Filter className="size-5" /> Todos los Roles
                        </Button>
                    </div>
                </div>

                {/* Contenido Principal */}
                <div className="flex-1 p-6 lg:p-10 overflow-auto">
                    <div className="max-w-7xl mx-auto">
                        {filteredUsers.length === 0 ? (
                            <div className="flex flex-col items-center justify-center rounded-3xl border-2 border-dashed py-32 text-center bg-background/50 backdrop-blur-sm">
                                <div className="size-20 rounded-full bg-muted/50 flex items-center justify-center mb-6 animate-pulse">
                                    <Users className="size-10 text-muted-foreground/30" />
                                </div>
                                <h3 className="text-2xl font-bold text-foreground">No hay coincidencias</h3>
                                <p className="text-muted-foreground mt-2 max-w-sm px-6">
                                    {search ? `No pudimos encontrar a "${search}" en tu lista de equipo.` : 'Empieza a agregar a los miembros de tu equipo para colaborar.'}
                                </p>
                                {search && (
                                    <Button variant="link" onClick={() => setSearch('')} className="mt-4 text-primary font-bold">
                                        Ver todo el equipo
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {filteredUsers.map(user => (
                                    <div 
                                        key={user.id} 
                                        className={`group relative flex flex-col rounded-3xl border bg-card p-6 shadow-sm transition-all duration-300 hover:shadow-xl hover:-translate-y-1 hover:border-primary/30 overflow-hidden ${!user.active ? 'opacity-70 saturate-50' : ''}`}
                                    >
                                        {!user.active && (
                                            <div className="absolute top-0 right-0 bg-red-500 text-white text-[9px] px-4 py-1 font-black rounded-bl-2xl shadow-sm z-10">
                                                ACCESO RESTRINGIDO
                                            </div>
                                        )}
                                        
                                        <div className="flex items-center gap-4 mb-8">
                                            <div className={`relative size-16 flex items-center justify-center rounded-2xl shadow-inner transition-transform group-hover:scale-110 ${(user.roles?.[0]?.name || '').toLowerCase() === 'admin' ? 'bg-purple-50 text-purple-600 dark:bg-purple-900/20' : 'bg-blue-50 text-blue-600 dark:bg-blue-900/20'}`}>
                                                {(user.roles?.[0]?.name || '').toLowerCase() === 'admin' ? <Shield className="size-8" /> : <UserIcon className="size-8" />}
                                                {user.active && (
                                                    <div className="absolute -top-1 -right-1 size-4 bg-green-500 border-2 border-card rounded-full shadow-sm" />
                                                )}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-1.5 overflow-hidden">
                                                    <h3 className="font-bold text-foreground truncate">{user.name}</h3>
                                                    {user.id === currentUser.id && (
                                                        <span className="bg-green-100 text-green-700 dark:bg-green-900/40 text-[7px] font-black px-1.5 py-0.5 rounded-md uppercase shrink-0">Tú</span>
                                                    )}
                                                </div>
                                                <p className="text-xs text-muted-foreground truncate">{user.email}</p>
                                            </div>
                                        </div>

                                        <div className="space-y-4 mt-auto">
                                            <div className="flex items-center justify-between border-y border-slate-100 dark:border-slate-800 py-3">
                                                <span className="text-[10px] font-bold text-muted-foreground uppercase">Rol</span>
                                                {getRoleBadge(user.roles)}
                                            </div>
                                            
                                            <div className="grid grid-cols-2 gap-2">
                                                <Button asChild variant="secondary" size="sm" className="h-10 gap-2 font-bold rounded-xl transition-all active:scale-95">
                                                    <Link href={route('users.edit', user.id)}>
                                                        <Pencil className="size-3.5" /> Editar
                                                    </Link>
                                                </Button>
                                                {user.id !== currentUser.id ? (
                                                    <Button variant="ghost" size="sm" className="h-10 gap-2 text-destructive hover:bg-red-50 dark:hover:bg-red-950/30 rounded-xl font-bold transition-all" onClick={() => handleDelete(user)}>
                                                        <Trash2 className="size-3.5" /> Borrar
                                                    </Button>
                                                ) : (
                                                    <div className="h-10 flex items-center justify-center bg-slate-50 dark:bg-slate-900 rounded-xl">
                                                        <span className="text-[9px] text-muted-foreground font-bold italic">Cuenta Principal</span>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

UsersIndex.layout = page => <AppLayout breadcrumb={['Equipo']}>{page}</AppLayout>;
