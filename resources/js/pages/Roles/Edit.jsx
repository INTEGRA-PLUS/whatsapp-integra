import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { 
    Save, 
    ArrowLeft, 
    Shield, 
    ShieldCheck, 
    Check,
    TrendingUp,
    Info,
    AlertCircle
} from 'lucide-react';

export default function Edit({ role, availablePermissions, rolePermissions }) {
    const { data, setData, put, processing, errors } = useForm({
        name: role.name,
        permissions: rolePermissions || [],
    });

    const togglePermission = (id) => {
        const newPermissions = data.permissions.includes(id)
            ? data.permissions.filter(p => p !== id)
            : [...data.permissions, id];
        setData('permissions', newPermissions);
    };

    const toggleModule = (moduleName, permissionIds) => {
        const allInModule = permissionIds.every(id => data.permissions.includes(id));
        if (allInModule) {
            setData('permissions', data.permissions.filter(id => !permissionIds.includes(id)));
        } else {
            setData('permissions', [...new Set([...data.permissions, ...permissionIds])]);
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('roles.update', role.id));
    };

    return (
        <>
            <Head title={`Editar Rol: ${role.name}`} />
            <div className="max-w-6xl mx-auto p-6 lg:p-10">
                <div className="flex items-center justify-between mb-10">
                    <div className="flex items-center gap-4">
                        <Button asChild variant="outline" size="icon" className="rounded-full shadow-sm">
                            <Link href={route('roles.index')}>
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight text-foreground">Editar Rol: {role.name}</h1>
                            <p className="text-muted-foreground mt-1">Ajusta los permisos y el nivel de acceso.</p>
                        </div>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="grid lg:grid-cols-12 gap-8">
                    {/* Main Content */}
                    <div className="lg:col-span-8 space-y-8">
                        {/* Basic Info */}
                        <section className="bg-card border rounded-[2.5rem] p-8 shadow-sm">
                            <div className="flex items-center gap-3 mb-8">
                                <div className="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                                    <Shield className="size-5" />
                                </div>
                                <h2 className="text-xl font-bold">General</h2>
                            </div>
                            
                            <div className="space-y-4">
                                <div className="space-y-2">
                                    <label className="text-sm font-bold ml-1">Nombre del Rol</label>
                                    <input
                                        type="text"
                                        value={data.name}
                                        onChange={e => setData('name', e.target.value)}
                                        className="w-full h-14 bg-zinc-50 dark:bg-zinc-900 border-transparent rounded-2xl px-6 focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all text-lg font-bold"
                                        placeholder="Ej: Ventas, Soporte, Logística..."
                                        required
                                    />
                                    {errors.name && <p className="text-xs text-red-500 font-medium ml-1">{errors.name}</p>}
                                </div>
                            </div>
                        </section>

                        {/* Permissions Matrix */}
                        <section className="bg-card border rounded-[2.5rem] p-8 shadow-sm">
                            <div className="flex items-center gap-3 mb-8">
                                <div className="size-10 rounded-xl bg-purple-100 text-purple-600 dark:bg-purple-900/20 flex items-center justify-center">
                                    <TrendingUp className="size-5" />
                                </div>
                                <h2 className="text-xl font-bold">Matriz de Permisos</h2>
                            </div>

                            <div className="space-y-6">
                                {Object.entries(availablePermissions).map(([module, permissions]) => (
                                    <div key={module} className="bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-100 dark:border-zinc-800 rounded-3xl overflow-hidden">
                                        <div className="px-6 py-4 bg-zinc-100 dark:bg-zinc-900 flex items-center justify-between">
                                            <h3 className="font-black text-sm uppercase tracking-widest text-zinc-500">{module}</h3>
                                            <Button 
                                                type="button" 
                                                variant="ghost" 
                                                size="sm" 
                                                className="text-[10px] font-black uppercase tracking-tighter"
                                                onClick={() => toggleModule(module, permissions.map(p => p.id))}
                                            >
                                                {permissions.every(p => data.permissions.includes(p.id)) ? 'Desmarcar Todo' : 'Marcar Todo'}
                                            </Button>
                                        </div>
                                        <div className="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            {permissions.map(perm => (
                                                <div 
                                                    key={perm.id}
                                                    onClick={() => togglePermission(perm.id)}
                                                    className={`cursor-pointer flex items-center gap-3 p-4 rounded-2xl border-2 transition-all ${data.permissions.includes(perm.id) ? 'border-primary bg-primary/5 shadow-sm' : 'border-transparent bg-white dark:bg-zinc-950 hover:border-zinc-200 dark:hover:border-zinc-800'}`}
                                                >
                                                    <div className={`size-6 rounded-lg flex items-center justify-center transition-colors ${data.permissions.includes(perm.id) ? 'bg-primary text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-transparent'}`}>
                                                        <Check className="size-4" />
                                                    </div>
                                                    <span className={`text-sm font-bold capitalize ${data.permissions.includes(perm.id) ? 'text-foreground' : 'text-muted-foreground'}`}>
                                                        {perm.name.split('.')[1]}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    </div>

                    {/* Sidebar Actions */}
                    <div className="lg:col-span-4 space-y-6">
                        <div className="bg-black rounded-[2.5rem] p-8 text-white shadow-xl sticky top-10">
                            <h3 className="text-lg font-bold mb-6 flex items-center gap-2">
                                <ShieldCheck className="size-5 text-primary" /> Resumen del Rol
                            </h3>
                            
                            <div className="space-y-6">
                                <div className="p-5 bg-white/5 rounded-2xl border border-white/10">
                                    <div className="flex items-center justify-between mb-4">
                                        <span className="text-zinc-400 text-xs font-bold uppercase tracking-wider">Permisos</span>
                                        <span className="text-primary text-xs font-black uppercase">{data.permissions.length} Activos</span>
                                    </div>
                                    <p className="text-[10px] text-zinc-400 leading-relaxed italic">
                                        Los cambios se aplicarán instantáneamente a todos los usuarios que tengan asignado este rol.
                                    </p>
                                </div>

                                <div className="space-y-3 pt-4">
                                    <Button type="submit" size="lg" className="w-full h-14 rounded-2xl font-black text-md shadow-lg shadow-primary/20" disabled={processing}>
                                        <Save className="size-5 mr-2" /> ACTUALIZAR ROL
                                    </Button>
                                    <Button asChild variant="ghost" className="w-full text-zinc-400 hover:text-white hover:bg-white/5 font-bold">
                                        <Link href={route('roles.index')}>Cancelar</Link>
                                    </Button>
                                </div>

                                <div className="pt-8 border-t border-white/10 flex items-start gap-3">
                                    <AlertCircle className="size-5 text-primary shrink-0" />
                                    <div className="text-[10px] text-zinc-400 leading-relaxed italic">
                                        Creado el {new Date(role.created_at).toLocaleDateString()}. Todas las modificaciones son auditadas.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </>
    );
}

Edit.layout = page => <AppLayout breadcrumb={['Roles', 'Editar']}>{page}</AppLayout>;
