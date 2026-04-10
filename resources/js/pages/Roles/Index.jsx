import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { 
    Pencil, 
    Trash2, 
    Shield, 
    ShieldCheck,
    Plus,
    Lock,
    Users,
    ChevronRight
} from 'lucide-react';

export default function RolesIndex({ roles }) {
    function handleDelete(role) {
        if (!confirm('¿Estás seguro de que deseas eliminar este rol? Todos los usuarios con este rol perderán sus permisos.')) return;
        router.delete(route('roles.destroy', role.id));
    }

    return (
        <>
            <Head title="Roles y Permisos" />
            <div className="flex flex-col h-full">
                {/* Header */}
                <div className="bg-white dark:bg-black border-b relative overflow-hidden">
                    <div className="max-w-7xl mx-auto px-6 py-10 relative">
                        <div className="flex flex-col md:flex-row md:items-center justify-between gap-6">
                            <div>
                                <h1 className="text-4xl font-black tracking-tight text-foreground flex items-center gap-4">
                                    <div className="size-12 bg-primary text-primary-foreground rounded-2xl flex items-center justify-center rotate-3">
                                        <ShieldCheck className="size-7" />
                                    </div>
                                    Roles y Permisos
                                </h1>
                                <p className="text-muted-foreground mt-2 text-lg">Define los niveles de acceso y módulos dinámicos del sistema.</p>
                            </div>
                            <Button asChild size="lg" className="gap-2 shadow-xl shadow-primary/20 h-12 px-8 rounded-xl transition-all hover:scale-105 active:scale-95">
                                <Link href={route('roles.create')}>
                                    <Plus className="size-5" /> Crear Nuevo Rol
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Content */}
                <div className="flex-1 p-6 lg:p-10">
                    <div className="max-w-7xl mx-auto">
                        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                            {roles.map(role => (
                                <div 
                                    key={role.id} 
                                    className="group bg-card border rounded-[2rem] p-8 shadow-sm hover:shadow-xl transition-all duration-300 flex flex-col relative overflow-hidden"
                                >
                                    <div className="flex items-center justify-between mb-6">
                                        <div className="size-14 rounded-2xl bg-zinc-100 dark:bg-zinc-900 flex items-center justify-center text-zinc-600 dark:text-zinc-400">
                                            <Shield className="size-7" />
                                        </div>
                                        <div className="flex gap-2">
                                            <Button asChild variant="ghost" size="icon" className="rounded-full hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                                <Link href={route('roles.edit', role.id)}>
                                                    <Pencil className="size-4" />
                                                </Link>
                                            </Button>
                                            <Button variant="ghost" size="icon" className="rounded-full text-destructive hover:bg-red-50 dark:hover:bg-red-950/30" onClick={() => handleDelete(role)}>
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </div>

                                    <h3 className="text-2xl font-black text-foreground mb-2 capitalize">{role.name}</h3>
                                    <div className="flex items-center gap-2 text-muted-foreground mb-8">
                                        <Lock className="size-4" />
                                        <span className="text-sm font-medium">{role.permissions.length} permisos asignados</span>
                                    </div>

                                    <div className="mt-auto pt-6 border-t border-zinc-100 dark:border-zinc-800">
                                        <div className="flex flex-wrap gap-2">
                                            {role.permissions.slice(0, 3).map(perm => (
                                                <span key={perm.id} className="px-3 py-1 bg-zinc-100 dark:bg-zinc-900 text-zinc-600 dark:text-zinc-400 rounded-lg text-[10px] font-bold uppercase tracking-wider">
                                                    {perm.name.split('.')[1]}
                                                </span>
                                            ))}
                                            {role.permissions.length > 3 && (
                                                <span className="px-3 py-1 bg-primary/10 text-primary rounded-lg text-[10px] font-bold uppercase tracking-wider">
                                                    +{role.permissions.length - 3}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>

                        {roles.length === 0 && (
                            <div className="text-center py-20 bg-zinc-50 dark:bg-zinc-900/50 rounded-[3rem] border-2 border-dashed">
                                <Shield className="size-20 text-muted-foreground/20 mx-auto mb-6" />
                                <h3 className="text-2xl font-bold text-foreground">No hay roles definidos</h3>
                                <p className="text-muted-foreground mt-2">Comienza creando un rol para gestionar los permisos de tu equipo.</p>
                                <Button asChild className="mt-8 rounded-xl" size="lg">
                                    <Link href={route('roles.create')}>Crear mi primer rol</Link>
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

RolesIndex.layout = page => <AppLayout breadcrumb={['Roles']}>{page}</AppLayout>;
