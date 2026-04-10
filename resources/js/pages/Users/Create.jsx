import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { 
    UserPlus, 
    ArrowLeft, 
    User, 
    Mail, 
    Lock, 
    ShieldCheck, 
    UserCircle,
    CheckCircle2,
    Check,
    X,
    Shield,
    Activity,
    User as UserIcon,
    TrendingUp
} from 'lucide-react';

export default function Create({ roles }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        role_id: roles.find(r => r.name.toLowerCase() === 'agent')?.id || roles.find(r => r.name.toLowerCase() === 'agente')?.id || (roles[0]?.id || ''),
        active: true,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('users.store'));
    };

    const PermissionRow = ({ label, admin, agent, user }) => (
        <tr className="border-b last:border-0 border-slate-100 dark:border-slate-800">
            <td className="py-3 text-xs font-medium text-foreground">{label}</td>
            <td className="py-3 text-center">{admin ? <Check className="size-4 text-green-500 mx-auto" /> : <X className="size-4 text-slate-300 mx-auto" />}</td>
            <td className="py-3 text-center">{agent ? <Check className="size-4 text-green-500 mx-auto" /> : <X className="size-4 text-slate-300 mx-auto" />}</td>
            <td className="py-3 text-center">{user ? <Check className="size-4 text-green-500 mx-auto" /> : <X className="size-4 text-slate-300 mx-auto" />}</td>
        </tr>
    );

    return (
        <>
            <Head title="Nuevo Usuario" />
            <div className="max-w-6xl mx-auto p-6 lg:p-10">
                <div className="flex items-center gap-4 mb-10">
                    <Button asChild variant="outline" size="icon" className="rounded-full shadow-sm">
                        <Link href={route('users.index')}>
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-3xl font-black tracking-tight text-foreground">Nuevo Miembro</h1>
                        <p className="text-muted-foreground mt-1">Registra un nuevo colaborador en la plataforma.</p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="grid lg:grid-cols-12 gap-8">
                    {/* Columna Izquierda: Formularios (8 columnas) */}
                    <div className="lg:col-span-8 space-y-8">
                        <section className="bg-card border rounded-3xl p-8 shadow-sm">
                            <div className="flex items-center gap-3 mb-8">
                                <div className="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                                    <User className="size-5" />
                                </div>
                                <h2 className="text-xl font-bold">Datos Personales</h2>
                            </div>
                            
                            <div className="grid sm:grid-cols-2 gap-6">
                                <div className="space-y-2">
                                    <label className="text-sm font-bold ml-1">Nombre Completo</label>
                                    <div className="relative group">
                                        <UserCircle className="absolute left-3 top-1/2 -translate-y-1/2 size-5 text-muted-foreground group-focus-within:text-primary transition-colors" />
                                        <input
                                            type="text"
                                            value={data.name}
                                            onChange={e => setData('name', e.target.value)}
                                            className="w-full h-12 bg-slate-50 dark:bg-slate-900 border-transparent rounded-2xl pl-11 pr-4 focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all text-sm font-medium"
                                            placeholder="Ej: Alejandro Magno"
                                            required
                                        />
                                    </div>
                                    {errors.name && <p className="text-xs text-red-500 font-medium ml-1">{errors.name}</p>}
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-bold ml-1">Correo Corporativo</label>
                                    <div className="relative group">
                                        <Mail className="absolute left-3 top-1/2 -translate-y-1/2 size-5 text-muted-foreground group-focus-within:text-primary transition-colors" />
                                        <input
                                            type="email"
                                            value={data.email}
                                            onChange={e => setData('email', e.target.value)}
                                            className="w-full h-12 bg-slate-50 dark:bg-slate-900 border-transparent rounded-2xl pl-11 pr-4 focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all text-sm font-medium"
                                            placeholder="alejandro@empresa.com"
                                            required
                                        />
                                    </div>
                                    {errors.email && <p className="text-xs text-red-500 font-medium ml-1">{errors.email}</p>}
                                </div>

                                <div className="space-y-2 sm:col-span-2">
                                    <label className="text-sm font-bold ml-1 text-primary flex items-center gap-2">
                                        <Lock className="size-3.5" /> Contraseña de Acceso
                                    </label>
                                    <input
                                        type="password"
                                        value={data.password}
                                        onChange={e => setData('password', e.target.value)}
                                        className="w-full h-12 bg-slate-50 dark:bg-slate-900 border-transparent rounded-2xl px-4 focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all text-sm font-medium"
                                        placeholder="Mínimo 8 caracteres"
                                        required
                                        minLength={8}
                                    />
                                    {errors.password && <p className="text-xs text-red-500 font-medium ml-1">{errors.password}</p>}
                                </div>
                            </div>
                        </section>

                        {/* Roles interactivos */}
                        <section className="bg-card border rounded-3xl p-8 shadow-sm">
                            <div className="flex items-center gap-3 mb-8">
                                <div className="size-10 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center dark:bg-purple-900/20">
                                    <ShieldCheck className="size-5" />
                                </div>
                                <h2 className="text-xl font-bold">Asignación de Rol</h2>
                            </div>

                            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                {roles.map(role => (
                                    <div 
                                        key={role.id}
                                        onClick={() => setData('role_id', role.id)}
                                        className={`cursor-pointer rounded-2xl border-2 p-5 transition-all relative overflow-hidden group ${data.role_id === role.id ? 'border-primary bg-primary/5 ring-4 ring-primary/10' : 'border-slate-100 dark:border-slate-800 hover:border-slate-200 dark:hover:border-slate-700'}`}
                                    >
                                        <div className="flex items-center justify-between mb-4">
                                            <div className={`size-10 rounded-xl flex items-center justify-center ${data.role_id === role.id ? 'bg-primary text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-400'}`}>
                                                {role.name.toLowerCase() === 'admin' ? <Shield className="size-5" /> : <UserIcon className="size-5" />}
                                            </div>
                                            {data.role_id === role.id && <Check className="size-5 text-primary" />}
                                        </div>
                                        <h3 className="font-bold text-sm capitalize">{role.name}</h3>
                                        <p className="text-[10px] text-muted-foreground mt-1">Acceso nivel {role.name}</p>
                                    </div>
                                ))}
                            </div>
                        </section>
                    </div>

                    {/* Columna Derecha: Guía de Permisos (4 columnas) */}
                    <div className="lg:col-span-4 space-y-6">
                        <div className="bg-black dark:bg-black rounded-3xl p-8 text-white shadow-xl shadow-slate-200 dark:shadow-none sticky top-10">
                            <h3 className="text-lg font-bold mb-6 flex items-center gap-2">
                                <TrendingUp className="size-5 text-primary" /> Guía de Permisos
                            </h3>
                            <div className="overflow-hidden rounded-2xl bg-white/5 border border-white/10 p-2">
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-b border-white/10">
                                            <th className="py-2 text-[10px] text-left text-slate-400 font-black uppercase">Acción</th>
                                            <th className="py-2 text-[10px] font-black uppercase">Adm</th>
                                            <th className="py-2 text-[10px] font-black uppercase">Age</th>
                                            <th className="py-2 text-[10px] font-black uppercase">Usr</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <PermissionRow label="Ver Chats" admin agent user />
                                        <PermissionRow label="Responder" admin agent />
                                        <PermissionRow label="Configuración" admin />
                                        <PermissionRow label="Reportes" admin agent />
                                        <PermissionRow label="Borrar Datos" admin />
                                        <PermissionRow label="Gestión CRM" admin agent />
                                    </tbody>
                                </table>
                            </div>

                            <div className="mt-8 space-y-6">
                                <div className="flex items-center justify-between p-4 bg-white/5 rounded-2xl border border-white/10">
                                    <div>
                                        <p className="text-sm font-bold">Acceso Activo</p>
                                        <p className="text-[10px] text-slate-400">¿Habilitar sesión ahora?</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setData('active', !data.active)}
                                        className={`relative inline-flex h-6 w-11 rounded-full transition-colors ${data.active ? 'bg-primary' : 'bg-slate-700'}`}
                                    >
                                        <span className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform mt-1 ${data.active ? 'translate-x-6' : 'translate-x-1'}`} />
                                    </button>
                                </div>

                                <div className="space-y-3">
                                    <Button type="submit" size="lg" className="w-full h-14 rounded-2xl font-black text-md shadow-lg shadow-primary/20" disabled={processing}>
                                        <UserPlus className="size-5 mr-2" /> FINALIZAR REGISTRO
                                    </Button>
                                    <Button asChild variant="ghost" className="w-full text-slate-400 hover:text-white hover:bg-white/5 font-bold">
                                        <Link href={route('users.index')}>Descartar cambios</Link>
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </>
    );
}

Create.layout = page => <AppLayout breadcrumb={['Usuarios', 'Nuevo']}>{page}</AppLayout>;
