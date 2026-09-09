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

export default function RolesIndex({ roles, modulos = [] }) {
    // La tarjeta mostraba `perm.name.split('.')[1]`: tres chips que decían
    // «view», «create», «update» y no distinguían un rol de otro. Lo que
    // importa saber de un vistazo es a qué MÓDULOS entra, en español.
    const modulosDelRol = (role) => {
        const ids = role.permissions.map((p) => p.id);
        const nombres = [];

        modulos.forEach((grupo) => {
            if (grupo.sobrante) return;
            grupo.modulos.forEach((modulo) => {
                if (modulo.permisos.some((p) => ids.includes(p.id))) {
                    nombres.push(modulo.nombre);
                }
            });
        });

        return nombres;
    };

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
                                <p className="text-muted-foreground mt-2 text-lg">Define a qué parte del sistema entra cada persona del equipo.</p>
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
                                        <div className="size-14 rounded-2xl bg-muted flex items-center justify-center text-muted-foreground">
                                            <Shield className="size-7" />
                                        </div>
                                        <div className="flex gap-2">
                                            <Button asChild variant="ghost" size="icon" className="rounded-full hover:bg-muted dark:hover:bg-muted">
                                                <Link href={route('roles.edit', role.id)}>
                                                    <Pencil className="size-4" />
                                                </Link>
                                            </Button>
                                            <Button variant="ghost" size="icon" className="rounded-full text-destructive hover:bg-destructive/15 dark:hover:bg-destructive/30" onClick={() => handleDelete(role)}>
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </div>

                                    <h3 className="text-2xl font-black text-foreground mb-2 capitalize">{role.name}</h3>
                                    {role.description ? (
                                        <p className="text-sm text-muted-foreground leading-snug mb-4">{role.description}</p>
                                    ) : (
                                        <p className="text-sm text-muted-foreground/60 italic mb-4">Sin descripción</p>
                                    )}
                                    <div className="flex items-center gap-2 text-muted-foreground mb-8">
                                        <Lock className="size-4" />
                                        <span className="text-sm font-medium">{role.permissions.length} permisos asignados</span>
                                    </div>

                                    <div className="mt-auto pt-6 border-t border-border">
                                        {(() => {
                                            const nombres = modulosDelRol(role);

                                            if (nombres.length === 0) {
                                                return (
                                                    <p className="text-xs text-muted-foreground italic">
                                                        Sin acceso a ningún módulo
                                                    </p>
                                                );
                                            }

                                            return (
                                                <div className="flex flex-wrap gap-2">
                                                    {nombres.slice(0, 4).map(nombre => (
                                                        <span key={nombre} className="px-3 py-1 bg-muted text-muted-foreground rounded-lg text-[11px] font-semibold">
                                                            {nombre}
                                                        </span>
                                                    ))}
                                                    {nombres.length > 4 && (
                                                        <span className="px-3 py-1 bg-primary/10 text-primary rounded-lg text-[11px] font-semibold">
                                                            +{nombres.length - 4} más
                                                        </span>
                                                    )}
                                                </div>
                                            );
                                        })()}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {roles.length === 0 && (
                            <div className="text-center py-20 bg-muted rounded-[3rem] border-2 border-dashed">
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
