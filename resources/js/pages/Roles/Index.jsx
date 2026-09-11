import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { clsx } from 'clsx';
import { colorPorIndice } from '@/lib/paleta';
import {
    Pencil,
    Trash2,
    Shield,
    ShieldCheck,
    Plus,
    Lock,
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

    // Cuántos permisos hay en total. «102 permisos asignados» no dice nada sin
    // el denominador: 102 puede ser todo o puede ser la mitad.
    const totalPermisos = modulos.reduce(
        (suma, grupo) => suma + grupo.modulos.reduce((s, m) => s + m.permisos.length, 0),
        0
    );

    function handleDelete(role) {
        if (!confirm('¿Estás seguro de que deseas eliminar este rol? Todos los usuarios con este rol perderán sus permisos.')) return;
        router.delete(route('roles.destroy', role.id));
    }

    return (
        <>
            <Head title="Roles y Permisos" />
            <div className="flex flex-col h-full">
                {/* Header */}
                {/* La banda era `bg-white dark:bg-black`: en oscuro, negro puro
                    contra el navy de la marca, que no se parecen en nada. Ahora
                    es la superficie elevada del tema con un lavado del verde,
                    que funciona igual en los dos temas. */}
                <div className="relative overflow-hidden border-b border-border bg-card">
                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-r from-primary/[0.09] via-primary/[0.02] to-transparent" />
                    <div className="max-w-7xl mx-auto px-6 py-8 relative">
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
                            {roles.map((role, indice) => {
                                const tono = colorPorIndice(indice);
                                const nombres = modulosDelRol(role);
                                const cobertura = totalPermisos > 0
                                    ? Math.round((role.permissions.length / totalPermisos) * 100)
                                    : 0;

                                return (
                                    <div
                                        key={role.id}
                                        className={clsx(
                                            'group relative flex flex-col overflow-hidden rounded-3xl border bg-card shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg',
                                            tono.borde
                                        )}
                                    >
                                        {/* La barra de color es lo que distingue una tarjeta de
                                            otra: antes las dos eran el mismo cuadro gris con el
                                            mismo escudo. */}
                                        <div className={clsx('h-1 shrink-0', tono.barra)} />

                                        <div className={clsx('flex items-center justify-between px-6 pt-5 pb-4', tono.tenue)}>
                                            <div className={clsx('size-11 rounded-2xl flex items-center justify-center text-white dark:text-background', tono.punto)}>
                                                <Shield className="size-5" />
                                            </div>
                                            <div className="flex gap-1 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
                                                <Button asChild variant="ghost" size="icon" className="rounded-full hover:bg-card">
                                                    <Link href={route('roles.edit', role.id)} title="Editar el rol">
                                                        <Pencil className="size-4" />
                                                    </Link>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Eliminar el rol"
                                                    className="rounded-full text-destructive hover:bg-destructive/15 dark:hover:bg-destructive/30"
                                                    onClick={() => handleDelete(role)}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        </div>

                                        <div className="flex flex-1 flex-col px-6 pb-6">
                                            <h3 className="mb-1 text-xl font-black capitalize text-foreground">{role.name}</h3>
                                            {role.description ? (
                                                <p className="mb-5 text-sm leading-snug text-muted-foreground">{role.description}</p>
                                            ) : (
                                                <p className="mb-5 text-sm italic text-muted-foreground/60">Sin descripción</p>
                                            )}

                                            {/* «102 permisos asignados» no dice nada sin el
                                                denominador: la barra enseña de un vistazo si el
                                                rol entra a todo o a un rincón. */}
                                            <div className="mb-5">
                                                <div className="mb-2 flex items-baseline justify-between gap-2">
                                                    <span className="flex items-center gap-1.5 text-sm font-bold text-foreground">
                                                        <Lock className="size-3.5 text-muted-foreground" />
                                                        {role.permissions.length}
                                                        <span className="font-medium text-muted-foreground">
                                                            de {totalPermisos} permisos
                                                        </span>
                                                    </span>
                                                    <span className={clsx('text-[11px] font-black tabular-nums', tono.texto)}>{cobertura}%</span>
                                                </div>
                                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className={clsx('h-full rounded-full transition-all', tono.barra)}
                                                        style={{ width: `${Math.max(cobertura, role.permissions.length > 0 ? 3 : 0)}%` }}
                                                    />
                                                </div>
                                            </div>

                                            <div className="mt-auto border-t border-border pt-4">
                                                {nombres.length === 0 ? (
                                                    <p className="text-xs italic text-muted-foreground">Sin acceso a ningún módulo</p>
                                                ) : (
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {nombres.slice(0, 4).map(nombre => (
                                                            <span key={nombre} className="rounded-lg bg-muted px-2.5 py-1 text-[11px] font-semibold text-muted-foreground">
                                                                {nombre}
                                                            </span>
                                                        ))}
                                                        {nombres.length > 4 && (
                                                            <span
                                                                className={clsx('rounded-lg px-2.5 py-1 text-[11px] font-black', tono.tenue, tono.texto)}
                                                                title={nombres.slice(4).join(', ')}
                                                            >
                                                                +{nombres.length - 4} más
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
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
