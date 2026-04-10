import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { 
    Save, 
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
    History,
    TrendingUp,
    AlertCircle
} from 'lucide-react';

export default function Edit({ user, roles, userRoleId }) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.name,
        email: user.email,
        password: '',
        role_id: userRoleId || (roles[0]?.id || ''),
        active: !!user.active,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('users.update', user.id));
    };

    const PermissionRow = ({ label, admin, agent, user: isUser }) => (
        <tr className="border-b last:border-0 border-slate-100 dark:border-slate-800">
            <td className="py-3 text-xs font-medium text-foreground">{label}</td>
            <td className="py-3 text-center">{admin ? <Check className="size-4 text-green-500 mx-auto" /> : <X className="size-4 text-slate-300 mx-auto" />}</td>
            <td className="py-3 text-center">{agent ? <Check className="size-4 text-green-500 mx-auto" /> : <X className="size-4 text-slate-300 mx-auto" />}</td>
            <td className="py-3 text-center">{isUser ? <Check className="size-4 text-green-500 mx-auto" /> : <X className="size-4 text-slate-300 mx-auto" />}</td>
        </tr>
    );

    return (
        <>
            <Head title={`Editar: ${user.name}`} />
            <div className="max-w-6xl mx-auto p-6 lg:p-10">
                <div className="flex items-center gap-4 mb-10">
                    <Button asChild variant="outline" size="icon" className="rounded-full shadow-sm">
                        <Link href={route('users.index')}>
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-3xl font-black tracking-tight text-foreground">Editar Perfil</h1>
                        <p className="text-muted-foreground mt-1">Gestiona los detalles y accesos de {user.name}.</p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="grid lg:grid-cols-12 gap-8">
                    {/* Columna Izquierda: Formularios */}
                    <div className="lg:col-span-8 space-y-8">
                        <section className="bg-card border rounded-3xl p-8 shadow-sm">
                            <div className="flex items-center gap-3 mb-8">
                                <div className="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                                    <User className="size-5" />
                                </div>
                                <h2 className="text-xl font-bold">Información de Cuenta</h2>
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
                                            required
                                        />
                                    </div>
                                    {errors.name && <p className="text-xs text-red-500 font-medium ml-1">{errors.name}</p>}
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-bold ml-1">Correo Electrónico</label>
                                    <div className="relative group">
                                        <Mail className="absolute left-3 top-1/2 -translate-y-1/2 size-5 text-muted-foreground group-focus-within:text-primary transition-colors" />
                                        <input
                                            type="email"
                                            value={data.email}
                                            onChange={e => setData('email', e.target.value)}
                                            className="w-full h-12 bg-slate-50 dark:bg-slate-900 border-transparent rounded-2xl pl-11 pr-4 focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all text-sm font-medium"
                                            required
                                        />
                                    </div>
                                    {errors.email && <p className="text-xs text-red-500 font-medium ml-1">{errors.email}</p>}
                                </div>

                                <div className="space-y-2 sm:col-span-2">
                                    <div className="flex items-center justify-between ml-1 mb-2">
                                        <label className="text-sm font-bold text-primary flex items-center gap-2">
                                            <Lock className="size-3.5" /> Cambiar Contraseña
                                        </label>
                                        <span className="text-[10px] font-black bg-amber-100 text-amber-700 dark:bg-amber-900/30 px-2 py-0.5 rounded-md uppercase">Opcional</span>
                                    </div>
                                    <input
                                        type="password"
                                        value={data.password}
                                        onChange={e => setData('password', e.target.value)}
                                        className="w-full h-12 bg-slate-50 dark:bg-slate-900 border-transparent rounded-2xl px-4 focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all text-sm font-medium"
                                        placeholder="Dejar en blanco para no modificar"
                                        minLength={8}
                                    />
                                    {errors.password && <p className="text-xs text-red-500 font-medium ml-1">{errors.password}</p>}
                                </div>
                            </div>
                        </section>

                        <section className="bg-card border rounded-3xl p-8 shadow-sm">
                            <div className="flex items-center gap-3 mb-8">
                                <div className="size-10 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center dark:bg-purple-900/20">
                                    <ShieldCheck className="size-5" />
                                </div>
                                <h2 className="text-xl font-bold">Rol</h2>
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

                    {/* Columna Derecha */}
                    <div className="lg:col-span-4 space-y-6">
                        <div className="bg-black dark:bg-black rounded-3xl p-8 text-white shadow-xl sticky top-10">
                            <h3 className="text-lg font-bold mb-6 flex items-center gap-2">
                                <TrendingUp className="size-5 text-primary" /> Permisos del Rol
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
                                        <p className="text-sm font-bold">Estado de Acceso</p>
                                        <p className={`text-[10px] font-bold ${data.active ? 'text-green-400' : 'text-red-400'}`}>
                                            {data.active ? 'Actualmente con acceso' : 'Acceso deshabilitado'}
                                        </p>
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
                                        <Save className="size-5 mr-2" /> ACTUALIZAR DATOS
                                    </Button>
                                    <Button asChild variant="ghost" className="w-full text-slate-400 hover:text-white hover:bg-white/5 font-bold">
                                        <Link href={route('users.index')}>Regresar sin guardar</Link>
                                    </Button>
                                </div>
                            </div>
                            
                            <div className="mt-8 pt-8 border-t border-white/10 flex items-start gap-3">
                                <AlertCircle className="size-5 text-primary shrink-0" />
                                <div className="text-[10px] text-slate-400 leading-relaxed italic">
                                    Creado el {new Date(user.created_at).toLocaleDateString()}. Los cambios son registrados en la bitácora de auditoría.
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </>
    );
}

Edit.layout = page => <AppLayout breadcrumb={['Usuarios', 'Editar']}>{page}</AppLayout>;
