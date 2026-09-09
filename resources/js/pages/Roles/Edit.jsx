import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { AlertCircle, ArrowLeft, Save } from 'lucide-react';
import EditorAccesos from '@/components/roles/EditorAccesos';
import ResumenAccesos from '@/components/roles/ResumenAccesos';

export default function Edit({ role, grupos, niveles, rolePermissions }) {
    const { data, setData, put, processing, errors } = useForm({
        name: role.name,
        description: role.description || '',
        permissions: rolePermissions || [],
    });

    const esAdmin = String(role.name).toLowerCase() === 'admin';

    const totalPermisos = useMemo(
        () => grupos.reduce((n, g) => n + g.modulos.reduce((m, mod) => m + mod.permisos.length, 0), 0),
        [grupos],
    );

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('roles.update', role.id));
    };

    return (
        <>
            <Head title={`Editar rol: ${role.name}`} />

            <div className="mx-auto max-w-6xl p-6 lg:p-10">
                <div className="mb-8 flex items-center gap-4">
                    <Button asChild variant="outline" size="icon" className="rounded-full">
                        <Link href={route('roles.index')} aria-label="Volver a roles">
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">
                            Editar «{role.name}»
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Los cambios se aplican de inmediato a todas las personas con este rol.
                        </p>
                    </div>
                </div>

                {esAdmin && (
                    <div className="mb-8 flex items-start gap-3 rounded-2xl border border-amber-500/40 bg-amber-500/[0.07] p-5">
                        <AlertCircle className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            Este es el rol de <span className="font-semibold text-foreground">administrador</span>.
                            Quitarle accesos aquí puede dejar a la empresa sin nadie que pueda
                            configurar el sistema, incluida esta misma pantalla.
                        </p>
                    </div>
                )}

                <form onSubmit={handleSubmit} className="grid gap-8 lg:grid-cols-12">
                    <div className="space-y-8 lg:col-span-8">
                        <div className="grid gap-4 rounded-2xl border bg-card p-6 sm:grid-cols-2">
                            <div>
                                <label
                                    htmlFor="rol-nombre"
                                    className="mb-2 block text-sm font-semibold text-foreground"
                                >
                                    Nombre del rol
                                </label>
                                <input
                                    id="rol-nombre"
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="h-12 w-full rounded-xl border bg-background px-4 transition-colors focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/10"
                                    required
                                />
                                {errors.name && (
                                    <p className="mt-2 text-sm text-destructive">{errors.name}</p>
                                )}
                            </div>
                            <div>
                                <label
                                    htmlFor="rol-descripcion"
                                    className="mb-2 block text-sm font-semibold text-foreground"
                                >
                                    ¿Para qué sirve?{' '}
                                    <span className="font-normal text-muted-foreground">(opcional)</span>
                                </label>
                                <input
                                    id="rol-descripcion"
                                    type="text"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    className="h-12 w-full rounded-xl border bg-background px-4 transition-colors focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/10"
                                    placeholder="Atiende chats y actualiza contactos"
                                    maxLength={255}
                                />
                                {errors.description && (
                                    <p className="mt-2 text-sm text-destructive">{errors.description}</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-bold text-foreground">
                                        ¿A qué puede entrar este rol?
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Los módulos están agrupados igual que el menú lateral.
                                    </p>
                                </div>
                                <span className="rounded-lg bg-muted px-3 py-1.5 text-xs font-semibold text-muted-foreground">
                                    {data.permissions.length} de {totalPermisos} permisos
                                </span>
                            </div>

                            <EditorAccesos
                                grupos={grupos}
                                seleccionados={data.permissions}
                                onCambiar={(ids) => setData('permissions', ids)}
                                niveles={niveles}
                            />
                        </div>
                    </div>

                    <div className="lg:col-span-4">
                        <div className="sticky top-6 space-y-4">
                            <h2 className="text-sm font-bold uppercase tracking-wider text-muted-foreground">
                                Cómo queda
                            </h2>

                            <ResumenAccesos
                                grupos={grupos}
                                seleccionados={data.permissions}
                                niveles={niveles}
                                nombre={data.name}
                            />

                            <div className="space-y-2 pt-2">
                                <Button type="submit" className="w-full" disabled={processing}>
                                    <Save className="mr-2 size-4" />
                                    {processing ? 'Guardando…' : 'Guardar cambios'}
                                </Button>
                                <Button asChild variant="ghost" className="w-full">
                                    <Link href={route('roles.index')}>Descartar cambios</Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </>
    );
}

Edit.layout = (page) => <AppLayout breadcrumb={['Roles', 'Editar']}>{page}</AppLayout>;
