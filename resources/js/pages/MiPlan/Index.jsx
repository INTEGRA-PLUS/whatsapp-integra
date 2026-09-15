import { Head, Link } from '@inertiajs/react';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Check, Lock, Sparkles, Users, Gift, ArrowRight } from 'lucide-react';
import { iconFor } from '@/pages/Extensions/icons';

/**
 * «Mi plan», tal y como lo ve el cliente.
 *
 * Deliberadamente no enseña precios ni costes: el precio depende del tramo, del
 * pago anual y de lo negociado, y un número suelto aquí acaba contradiciendo a
 * un comercial. Lo que sí enseña es qué tiene, cuánto le queda y qué se
 * desbloquea subiendo — que es la parte que decide.
 */
export default function MiPlan({ plan, uso_ia, extensiones, planes }) {
    const incluidas = extensiones.filter(e => e.en_plan);
    const bloqueadas = extensiones.filter(e => !e.en_plan);

    return (
        <>
            <Head title="Mi plan" />

            <div className="flex flex-col gap-6 p-6 lg:p-8 max-w-5xl">

                <div>
                    <h1 className="text-2xl font-semibold text-foreground">Mi plan</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Lo que tienes contratado y lo que puedes activar.
                    </p>
                </div>

                {/* La cabecera: plan, contactos y mes gratis si lo hay. */}
                <div className="rounded-xl border bg-card p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-[11px] font-black uppercase tracking-widest text-muted-foreground">
                                Plan actual
                            </p>
                            <p className="mt-1 text-3xl font-semibold text-foreground">{plan.plan_nombre}</p>
                        </div>

                        {plan.en_mes_gratis && (
                            <div className="flex items-center gap-2 rounded-lg border border-success/30 bg-success/10 px-3 py-2">
                                <Gift className="size-4 shrink-0 text-success" />
                                <div className="text-xs">
                                    <p className="font-semibold text-foreground">Mes de cortesía</p>
                                    <p className="text-muted-foreground">
                                        Hasta el {new Date(plan.gratis_hasta).toLocaleDateString('es-CO')}
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="mt-6 grid gap-4 sm:grid-cols-2">
                        <Dato
                            icono={Users}
                            titulo="Contactos"
                            valor={plan.contactos_reales?.toLocaleString('es-CO') ?? '0'}
                            pie={plan.contactos_contratados
                                ? `de ${plan.contactos_contratados.toLocaleString('es-CO')} de tu plan`
                                : 'sin límite asignado'}
                            aviso={plan.se_paso_del_tramo}
                        />

                        {plan.tiene_ia ? (
                            <Dato
                                icono={Sparkles}
                                titulo="Conversaciones con IA este mes"
                                valor={uso_ia.usadas.toLocaleString('es-CO')}
                                pie={`de ${uso_ia.incluidas.toLocaleString('es-CO')} incluidas`}
                                barra={Math.min(100, uso_ia.porcentaje)}
                                aviso={uso_ia.exceso > 0}
                            />
                        ) : (
                            <Dato
                                icono={Sparkles}
                                titulo="Inteligencia artificial"
                                valor="No incluida"
                                pie="Disponible en el plan Inteligente"
                                apagado
                            />
                        )}
                    </div>

                    {plan.se_paso_del_tramo && (
                        <p className="mt-4 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-xs text-foreground">
                            Tienes más contactos de los que cubre tu plan. No se te ha limitado nada
                            —seguimos atendiendo a todos— pero conviene que hablemos para ajustarlo.
                        </p>
                    )}
                </div>

                {/* Lo que ya tiene. */}
                <section>
                    <h2 className="text-sm font-semibold text-foreground">
                        Incluido en tu plan
                        <span className="ml-2 font-normal text-muted-foreground">{incluidas.length}</span>
                    </h2>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        {incluidas.map(e => <Tarjeta key={e.slug} extension={e} />)}
                    </div>
                </section>

                {/* Y lo que no: enseñarlo es lo que abre la conversación. */}
                {bloqueadas.length > 0 && (
                    <section>
                        <h2 className="text-sm font-semibold text-foreground">
                            Disponible subiendo de plan
                            <span className="ml-2 font-normal text-muted-foreground">{bloqueadas.length}</span>
                        </h2>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            {bloqueadas.map(e => <Tarjeta key={e.slug} extension={e} bloqueada />)}
                        </div>

                        <div className="mt-5 flex flex-wrap items-center gap-3 rounded-xl border bg-muted/40 px-5 py-4">
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-foreground">
                                    ¿Te interesa algo de esto?
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Escríbenos y lo activamos. Cambiar de plan no interrumpe nada de lo que
                                    ya usas.
                                </p>
                            </div>
                            <Button asChild className="gap-2 shrink-0">
                                <a href="https://wa.me/573181454747?text=Hola,%20quiero%20saber%20m%C3%A1s%20sobre%20los%20planes%20de%20Integra%20CRM">
                                    Hablar con nosotros <ArrowRight className="size-4" />
                                </a>
                            </Button>
                        </div>
                    </section>
                )}

                {/* La escalera, para situarse. Sin precios, a propósito. */}
                <section>
                    <h2 className="text-sm font-semibold text-foreground">Los planes</h2>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {planes.map(p => (
                            <span
                                key={p.slug}
                                className={clsx(
                                    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold',
                                    p.es_el_suyo
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-border text-muted-foreground'
                                )}
                            >
                                {p.es_el_suyo && <Check className="size-3.5" />}
                                {p.nombre}
                                {p.con_ia && <Sparkles className="size-3" />}
                            </span>
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}

function Dato({ icono: Icono, titulo, valor, pie, barra, aviso, apagado }) {
    return (
        <div className={clsx('rounded-lg border p-4', aviso ? 'border-warning/40 bg-warning/5' : 'bg-background')}>
            <div className="flex items-center gap-1.5">
                <Icono className={clsx('size-3.5', apagado ? 'text-muted-foreground/60' : 'text-primary')} />
                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {titulo}
                </p>
            </div>
            <p className={clsx(
                'mt-1.5 text-2xl font-semibold tabular-nums',
                apagado ? 'text-muted-foreground' : 'text-foreground'
            )}>
                {valor}
            </p>
            <p className="text-xs text-muted-foreground">{pie}</p>

            {barra !== undefined && (
                <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-border">
                    <div
                        className={clsx('h-full rounded-full', aviso ? 'bg-warning' : 'bg-primary')}
                        style={{ width: `${barra}%` }}
                    />
                </div>
            )}
        </div>
    );
}

function Tarjeta({ extension, bloqueada }) {
    const Icono = iconFor(extension.icono);

    return (
        <div className={clsx(
            'flex items-start gap-3 rounded-lg border p-4',
            bloqueada ? 'border-dashed bg-muted/20' : 'bg-card'
        )}>
            <div className={clsx(
                'size-9 shrink-0 rounded-lg flex items-center justify-center',
                bloqueada
                    ? 'bg-muted text-muted-foreground'
                    : extension.encendida ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground'
            )}>
                <Icono className="size-4" />
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                    <p className={clsx(
                        'text-sm font-semibold',
                        bloqueada ? 'text-muted-foreground' : 'text-foreground'
                    )}>
                        {extension.nombre}
                    </p>

                    {bloqueada ? (
                        <span className="inline-flex shrink-0 items-center gap-1 rounded-md bg-muted px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                            <Lock className="size-2.5" /> {extension.plan_minimo}
                        </span>
                    ) : extension.encendida ? (
                        <span className="inline-flex shrink-0 items-center gap-1 text-[10px] font-bold uppercase tracking-wide text-success">
                            <Check className="size-3" /> Activa
                        </span>
                    ) : (
                        <span className="shrink-0 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                            {extension.instalada ? 'Apagada' : 'Sin instalar'}
                        </span>
                    )}
                </div>

                <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                    {extension.descripcion}
                </p>

                {/* El enlace sólo si puede hacer algo con él: mandar a alguien a
                    una pantalla donde el botón está bloqueado es un callejón. */}
                {!bloqueada && !extension.encendida && (
                    <Link
                        href={route('extensions.show', extension.slug)}
                        className="mt-1.5 inline-block text-xs font-semibold text-primary hover:underline"
                    >
                        {extension.instalada ? 'Encender' : 'Activar'}
                    </Link>
                )}
            </div>
        </div>
    );
}

MiPlan.layout = page => <AppLayout>{page}</AppLayout>;
