import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { ArrowLeft, ArrowRight, Check, Save, Sparkles } from 'lucide-react';
import { cn } from '@/lib/utils';
import EditorAccesos from '@/components/roles/EditorAccesos';
import ResumenAccesos from '@/components/roles/ResumenAccesos';
import { PLANTILLAS, idsDePlantilla } from '@/components/roles/permisos';

const PASOS = [
    { titulo: 'Nombre', ayuda: 'Cómo se llama y para qué sirve' },
    { titulo: 'Accesos', ayuda: 'A qué módulos puede entrar' },
    { titulo: 'Resumen', ayuda: 'Revisar y guardar' },
];

export default function Create({ grupos, niveles }) {
    const [paso, setPaso] = useState(0);
    const [plantillaUsada, setPlantillaUsada] = useState(null);

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        permissions: [],
    });

    const nombreValido = data.name.trim().length > 0;

    const totalPermisos = useMemo(
        () => grupos.reduce((n, g) => n + g.modulos.reduce((m, mod) => m + mod.permisos.length, 0), 0),
        [grupos],
    );

    const aplicarPlantilla = (plantilla) => {
        setPlantillaUsada(plantilla.clave);
        setData('permissions', idsDePlantilla(plantilla, grupos));
    };

    const siguiente = () => {
        if (paso === 0 && !nombreValido) return;
        setPaso((p) => Math.min(p + 1, PASOS.length - 1));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const anterior = () => {
        setPaso((p) => Math.max(p - 1, 0));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    // El formulario NUNCA guarda por sí solo. Guardar es una acción explícita
    // del botón del último paso.
    //
    // Con `onSubmit` haciendo el post, cualquier evento de submit del navegador
    // —un Enter en el campo de nombre, o el submit que dispara el botón de
    // «Continuar» justo después de que el paso ya cambió— creaba el rol sin que
    // nadie llegara a ver el resumen. Lo comprobé: pasaba de verdad.
    const bloquearSubmit = (e) => e.preventDefault();

    const guardar = () => post(route('roles.store'));

    return (
        <>
            <Head title="Nuevo rol" />

            <div className="mx-auto max-w-4xl p-6 lg:p-10">
                <div className="mb-8 flex items-center gap-4">
                    <Button asChild variant="outline" size="icon" className="rounded-full">
                        <Link href={route('roles.index')} aria-label="Volver a roles">
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">
                            Crear un rol
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Un rol es un paquete de accesos que después le asignas a varias personas.
                        </p>
                    </div>
                </div>

                {/* Pasos */}
                <ol className="mb-10 flex items-center gap-2">
                    {PASOS.map((p, i) => {
                        const hecho = i < paso;
                        const actual = i === paso;
                        return (
                            <li key={p.titulo} className="flex flex-1 items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => (i < paso || (i === 1 && nombreValido)) && setPaso(i)}
                                    disabled={i > paso && !(i === 1 && nombreValido)}
                                    className={cn(
                                        'flex min-w-0 flex-1 items-center gap-3 rounded-xl border p-3 text-left transition-colors',
                                        actual && 'border-primary bg-primary/5',
                                        hecho && 'border-border hover:bg-muted',
                                        !actual && !hecho && 'border-dashed opacity-60',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                            actual && 'bg-primary text-primary-foreground',
                                            hecho && 'bg-primary/15 text-primary',
                                            !actual && !hecho && 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {hecho ? <Check className="size-4" /> : i + 1}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-semibold text-foreground">
                                            {p.titulo}
                                        </span>
                                        <span className="hidden truncate text-xs text-muted-foreground sm:block">
                                            {p.ayuda}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ol>

                <form onSubmit={bloquearSubmit}>
                    {/* ── Paso 1 ── */}
                    {paso === 0 && (
                        <div className="space-y-6">
                            <div className="rounded-2xl border bg-card p-6">
                                <label
                                    htmlFor="rol-nombre"
                                    className="block text-sm font-semibold text-foreground"
                                >
                                    Nombre del rol
                                </label>
                                <p className="mb-3 text-sm text-muted-foreground">
                                    Como se conoce el cargo dentro de la empresa.
                                </p>
                                <input
                                    id="rol-nombre"
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="h-12 w-full rounded-xl border bg-background px-4 text-base transition-colors focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/10"
                                    placeholder="Agente de soporte"
                                    autoFocus
                                    required
                                />
                                {errors.name && (
                                    <p className="mt-2 text-sm text-destructive">{errors.name}</p>
                                )}
                            </div>

                            <div className="rounded-2xl border bg-card p-6">
                                <label
                                    htmlFor="rol-descripcion"
                                    className="block text-sm font-semibold text-foreground"
                                >
                                    ¿Para qué sirve? <span className="font-normal text-muted-foreground">(opcional)</span>
                                </label>
                                <p className="mb-3 text-sm text-muted-foreground">
                                    Una frase para que dentro de seis meses se sepa por qué existe este rol.
                                </p>
                                <input
                                    id="rol-descripcion"
                                    type="text"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    className="h-12 w-full rounded-xl border bg-background px-4 transition-colors focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/10"
                                    placeholder="Atiende chats y actualiza contactos, sin tocar campañas"
                                    maxLength={255}
                                />
                                {errors.description && (
                                    <p className="mt-2 text-sm text-destructive">{errors.description}</p>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ── Paso 2 ── */}
                    {paso === 1 && (
                        <div className="space-y-8">
                            <div className="rounded-2xl border bg-card p-6">
                                <div className="mb-1 flex items-center gap-2">
                                    <Sparkles className="size-4 text-primary" />
                                    <h2 className="font-semibold text-foreground">
                                        Empieza por un punto de partida
                                    </h2>
                                </div>
                                <p className="mb-4 text-sm text-muted-foreground">
                                    Elige el que más se parezca y ajústalo abajo. Puedes cambiar lo
                                    que quieras después.
                                </p>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {PLANTILLAS.map((plantilla) => (
                                        <button
                                            key={plantilla.clave}
                                            type="button"
                                            onClick={() => aplicarPlantilla(plantilla)}
                                            className={cn(
                                                'rounded-xl border p-4 text-left transition-colors',
                                                plantillaUsada === plantilla.clave
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-border hover:border-primary/40',
                                            )}
                                        >
                                            <span className="block text-sm font-semibold text-foreground">
                                                {plantilla.nombre}
                                            </span>
                                            <span className="mt-1 block text-xs leading-snug text-muted-foreground">
                                                {plantilla.ayuda}
                                            </span>
                                        </button>
                                    ))}
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
                                    onCambiar={(ids) => {
                                        setPlantillaUsada(null);
                                        setData('permissions', ids);
                                    }}
                                    niveles={niveles}
                                />
                            </div>
                        </div>
                    )}

                    {/* ── Paso 3 ── */}
                    {paso === 2 && (
                        <div className="space-y-6">
                            <div>
                                <h2 className="text-lg font-bold text-foreground">
                                    Esto es lo que vas a guardar
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {data.description || 'Sin descripción.'}
                                </p>
                            </div>

                            <ResumenAccesos
                                grupos={grupos}
                                seleccionados={data.permissions}
                                niveles={niveles}
                                nombre={data.name}
                            />
                        </div>
                    )}

                    {/* ── Navegación ── */}
                    <div className="mt-10 flex items-center justify-between gap-4 border-t pt-6">
                        {paso > 0 ? (
                            <Button type="button" variant="ghost" onClick={anterior}>
                                <ArrowLeft className="mr-2 size-4" /> Atrás
                            </Button>
                        ) : (
                            <Button asChild variant="ghost">
                                <Link href={route('roles.index')}>Cancelar</Link>
                            </Button>
                        )}

                        {paso < PASOS.length - 1 ? (
                            <Button type="button" onClick={siguiente} disabled={paso === 0 && !nombreValido}>
                                Continuar <ArrowRight className="ml-2 size-4" />
                            </Button>
                        ) : (
                            <Button type="button" onClick={guardar} disabled={processing}>
                                <Save className="mr-2 size-4" />
                                {processing ? 'Guardando…' : 'Crear rol'}
                            </Button>
                        )}
                    </div>
                </form>
            </div>
        </>
    );
}

Create.layout = (page) => <AppLayout breadcrumb={['Roles', 'Nuevo']}>{page}</AppLayout>;
