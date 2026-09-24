import { Head, Link, useForm } from '@inertiajs/react';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import CabeceraModulo from '@/components/cabecera-modulo';
import { AlertTriangle, Check, Loader2, Save, Shield, User as UserIcon, Users } from 'lucide-react';

/**
 * Editar a alguien del equipo.
 *
 * Tenía los mismos vicios que el alta —panel negro, esquinas de 3rem, campos de
 * 48px sobre gris, «ACTUALIZAR DATOS» en mayúsculas— y la misma «Guía de
 * permisos» escrita a mano que no correspondía a los roles de nadie.
 *
 * Y una frase que prometía lo que no había: «los cambios son registrados en la
 * bitácora de auditoría». No se registraba ninguno. Ahora sí se registran, así
 * que la frase se puede decir — y se dice con lo que de verdad se guarda.
 */
export default function Edit({ user, roles, userRoleId, es_uno_mismo }) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.name,
        email: user.email,
        password: '',
        role_id: userRoleId ?? roles[0]?.id ?? '',
        active: !!user.active,
    });

    const elegido = roles.find(r => r.id === data.role_id);
    const cambiaSuPropioAcceso = es_uno_mismo && (!data.active || data.role_id !== userRoleId);

    function enviar(e) {
        e.preventDefault();
        put(route('users.update', user.id));
    }

    return (
        <>
            <Head title={`Editar: ${user.name}`} />

            <div className="flex max-w-3xl flex-col gap-6 p-6 lg:p-8">
                <CabeceraModulo
                    icono={Users}
                    titulo={user.name}
                    descripcion={`En el equipo desde el ${new Date(user.created_at).toLocaleDateString('es-CO')}.`}
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
                                error={errors.name}
                                required
                            />
                            <Campo
                                label="Correo"
                                type="email"
                                value={data.email}
                                onChange={v => setData('email', v)}
                                ayuda="Con este correo entra al CRM."
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
                                    ayuda="Déjala vacía para no cambiarla. Si la escribes, la de ahora deja de servir."
                                    error={errors.password}
                                />
                            </div>
                        </div>
                    </section>

                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-sm font-semibold text-foreground">Qué puede hacer</h2>
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
                                            <p className="text-xs tabular-nums text-muted-foreground">
                                                {rol.permisos} {rol.permisos === 1 ? 'permiso' : 'permisos'}
                                                {rol.id === userRoleId && ' · el que tiene ahora'}
                                            </p>
                                        </div>
                                        {activo && <Check className="size-4 shrink-0 text-primary" />}
                                    </button>
                                );
                            })}
                        </div>

                        {elegido?.resumen?.length > 0 && (
                            <div className="mt-4 rounded-lg border bg-muted/40 p-4">
                                <p className="text-xs font-medium text-foreground">
                                    Con el rol <span className="capitalize">{elegido.name}</span> entra a:
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
                                Este rol no tiene ningún permiso asignado: con él podrá entrar, pero no verá
                                ninguna pantalla. Se le asignan en Roles.
                            </p>
                        )}
                    </section>

                    <section className="flex items-center justify-between gap-4 rounded-xl border bg-card p-5">
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-foreground">Puede entrar</p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                {data.active
                                    ? 'Su cuenta está activa y puede iniciar sesión.'
                                    : 'Apagado, la cuenta se conserva pero no deja iniciar sesión.'}
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

                    {/* Editarse a uno mismo es el camino corto a quedarse fuera:
                        quitarse el acceso o bajarse el rol se nota al recargar,
                        y para entonces ya no se puede deshacer desde aquí. */}
                    {cambiaSuPropioAcceso && (
                        <div className="flex items-start gap-3 rounded-xl border border-warning/40 bg-warning/[0.07] p-4">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
                            <p className="text-xs leading-relaxed text-foreground">
                                Estás cambiando tu propio acceso. Si te quitas permisos o desactivas tu cuenta,
                                lo notarás al recargar y ya no podrás deshacerlo desde aquí: tendrá que hacerlo
                                otro administrador.
                            </p>
                        </div>
                    )}

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={processing} className="gap-2">
                            {processing ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
                            Guardar cambios
                        </Button>
                        <Button asChild type="button" variant="outline">
                            <Link href={route('users.index')}>Cancelar</Link>
                        </Button>
                    </div>

                    <p className="text-[11px] text-muted-foreground">
                        Los cambios de datos, rol y contraseña quedan registrados con quién los hizo.
                    </p>
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

Edit.layout = page => <AppLayout breadcrumb={['Usuarios', 'Editar']}>{page}</AppLayout>;
