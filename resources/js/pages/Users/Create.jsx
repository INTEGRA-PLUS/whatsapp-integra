import { Head, Link, useForm } from '@inertiajs/react';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import CabeceraModulo from '@/components/cabecera-modulo';
import { Check, Loader2, Shield, User as UserIcon, UserPlus, Users } from 'lucide-react';

/**
 * Alta de un usuario de la empresa.
 *
 * Era una pantalla de dos columnas con esquinas de 3rem, campos de 48px sobre
 * gris, títulos en negras y un panel negro a la derecha con una «Guía de
 * permisos» — una tabla de seis filas y tres columnas (Adm/Age/Usr) escrita a
 * mano en el JSX.
 *
 * Esa tabla era el verdadero problema, más que el estilo: **no salía de ningún
 * sitio**. Los roles no son los mismos en todas las empresas —cada una define
 * los suyos— y las marcas no correspondían a los permisos reales de nadie. Quien
 * elegía un rol leyéndola creía estar informado y no lo estaba.
 *
 * Ahora lo que se enseña son los permisos de verdad del rol elegido, resumidos
 * por módulo con el mismo vocabulario de la pantalla de roles: solo ver, operar,
 * control total. Sale de `PermisosCatalogo::resumenDeRol()`.
 */
export default function Create({ roles }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        role_id: roles.find(r => ['agent', 'agente'].includes(r.name.toLowerCase()))?.id ?? roles[0]?.id ?? '',
        active: true,
    });

    const elegido = roles.find(r => r.id === data.role_id);

    function enviar(e) {
        e.preventDefault();
        post(route('users.store'));
    }

    return (
        <>
            <Head title="Nuevo usuario" />

            <div className="flex max-w-3xl flex-col gap-6 p-6 lg:p-8">
                <CabeceraModulo
                    icono={Users}
                    titulo="Nuevo usuario"
                    descripcion="Entrará al CRM con este correo y su contraseña."
                    volver={route('users.index')}
                />

                <form onSubmit={enviar} className="flex flex-col gap-6">
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-sm font-semibold text-foreground">Datos de la persona</h2>

                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <Campo
                                label="Nombre"
                                value={data.name}
                                onChange={v => setData('name', v)}
                                placeholder="María Restrepo"
                                error={errors.name}
                                required
                            />
                            <Campo
                                label="Correo"
                                type="email"
                                value={data.email}
                                onChange={v => setData('email', v)}
                                placeholder="maria@empresa.com"
                                ayuda="Con este correo entrará al CRM."
                                error={errors.email}
                                required
                            />
                            <div className="sm:col-span-2">
                                <Campo
                                    label="Contraseña"
                                    type="password"
                                    value={data.password}
                                    onChange={v => setData('password', v)}
                                    autoComplete="new-password"
                                    ayuda="Mínimo 8 caracteres. Podrá cambiarla cuando entre."
                                    error={errors.password}
                                    required
                                />
                            </div>
                        </div>
                    </section>

                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-sm font-semibold text-foreground">Qué podrá hacer</h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            El rol decide a qué pantallas entra y qué puede tocar en cada una.
                        </p>

                        <div className="mt-4 grid gap-2.5 sm:grid-cols-2">
                            {roles.map(rol => {
                                const activo = data.role_id === rol.id;

                                return (
                                    <button
                                        key={rol.id}
                                        type="button"
                                        onClick={() => setData('role_id', rol.id)}
                                        aria-pressed={activo}
                                        className={clsx(
                                            'flex items-center gap-3 rounded-lg border p-3 text-left transition-colors',
                                            activo ? 'border-primary bg-primary/[0.07]' : 'hover:border-primary/40'
                                        )}
                                    >
                                        <div className={clsx(
                                            'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                            activo ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground'
                                        )}>
                                            {rol.name.toLowerCase() === 'admin'
                                                ? <Shield className="size-4" />
                                                : <UserIcon className="size-4" />}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium capitalize text-foreground">{rol.name}</p>
                                            {/* El número de permisos, que es un dato y no
                                                un adorno: es lo que distingue dos roles con
                                                nombres parecidos. */}
                                            <p className="text-xs tabular-nums text-muted-foreground">
                                                {rol.permisos} {rol.permisos === 1 ? 'permiso' : 'permisos'}
                                            </p>
                                        </div>
                                        {activo && <Check className="size-4 shrink-0 text-primary" />}
                                    </button>
                                );
                            })}
                        </div>

                        {/* Lo que de verdad puede hacer el rol elegido, sacado de
                            sus permisos. Sólo los módulos donde puede algo: una
                            lista de treinta «sin acceso» esconde los cinco que
                            importan. */}
                        {elegido?.resumen?.length > 0 && (
                            <div className="mt-4 rounded-lg border bg-muted/40 p-4">
                                <p className="text-xs font-medium text-foreground">
                                    Con el rol <span className="capitalize">{elegido.name}</span> podrá entrar a:
                                </p>
                                <ul className="mt-2.5 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                                    {elegido.resumen.map(area => (
                                        <li key={area.modulo} className="flex items-baseline justify-between gap-3 text-xs">
                                            <span className="text-foreground">{area.modulo}</span>
                                            <span className={clsx(
                                                'shrink-0',
                                                area.nivel === 'total' ? 'text-warning' : 'text-muted-foreground'
                                            )}>
                                                {area.etiqueta}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                {/* «Control total» incluye borrar, y borrar en este
                                    producto es de verdad: no hay papelera. */}
                                {elegido.resumen.some(a => a.nivel === 'total') && (
                                    <p className="mt-3 text-[11px] leading-snug text-muted-foreground">
                                        <span className="text-warning">Control total</span> incluye eliminar, y aquí
                                        los borrados no tienen vuelta atrás.
                                    </p>
                                )}
                            </div>
                        )}

                        {elegido && elegido.resumen?.length === 0 && (
                            <p className="mt-4 rounded-lg border border-warning/40 bg-warning/[0.07] px-4 py-3 text-xs text-foreground">
                                Este rol no tiene ningún permiso asignado: quien lo tenga podrá entrar, pero no
                                verá ninguna pantalla. Se le asignan en Roles.
                            </p>
                        )}
                    </section>

                    <section className="flex items-center justify-between gap-4 rounded-xl border bg-card p-5">
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-foreground">Puede entrar desde ya</p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Apagado, la cuenta queda creada pero no deja iniciar sesión.
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={data.active}
                            onClick={() => setData('active', !data.active)}
                            className={clsx(
                                'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors',
                                data.active ? 'bg-primary' : 'bg-input'
                            )}
                        >
                            <span className={clsx(
                                'mt-1 inline-block size-4 rounded-full bg-white transition-transform',
                                data.active ? 'translate-x-6' : 'translate-x-1'
                            )} />
                        </button>
                    </section>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={processing} className="gap-2">
                            {processing ? <Loader2 className="size-4 animate-spin" /> : <UserPlus className="size-4" />}
                            Crear usuario
                        </Button>
                        <Button asChild type="button" variant="outline">
                            <Link href={route('users.index')}>Cancelar</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

function Campo({ label, value, onChange, type = 'text', placeholder = '', ayuda = '', error, required, autoComplete }) {
    return (
        <div className="space-y-1.5">
            <label className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                {label}
                {!required && <span className="text-[10px] font-normal text-muted-foreground">(opcional)</span>}
            </label>
            <input
                type={type}
                value={value}
                onChange={e => onChange(e.target.value)}
                placeholder={placeholder}
                required={required}
                autoComplete={autoComplete}
                className={clsx(
                    'h-10 w-full rounded-lg border bg-background px-3 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground/60 focus:ring-2 focus:ring-ring/20',
                    error ? 'border-destructive focus:border-destructive' : 'border-input focus:border-ring'
                )}
            />
            {error
                ? <p className="text-[11px] text-destructive">{error}</p>
                : ayuda && <p className="text-[11px] leading-snug text-muted-foreground">{ayuda}</p>}
        </div>
    );
}

Create.layout = page => <AppLayout breadcrumb={['Usuarios', 'Nuevo']}>{page}</AppLayout>;
