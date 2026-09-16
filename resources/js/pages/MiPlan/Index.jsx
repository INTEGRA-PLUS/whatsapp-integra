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
export default function MiPlan({ plan, uso_ia, extensiones, planes, complementos = [], nucleo = [] }) {
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

                    <div className="mt-6 grid gap-4 sm:grid-cols-3">
                        <Dato
                            icono={Users}
                            titulo="Contactos"
                            valor={plan.contactos_reales?.toLocaleString('es-CO') ?? '0'}
                            pie={`de ${plan.contactos_incluidos?.toLocaleString('es-CO')} de tu plan`}
                            aviso={plan.se_paso_de?.includes('contactos')}
                        />

                        {/* Los agentes, que antes no salían: desde que el plan
                            los incluye, es la mitad de lo que decide si le queda
                            corto — y es lo primero que crece en un equipo. */}
                        <Dato
                            icono={Users}
                            titulo="Agentes"
                            valor={plan.agentes_reales?.toLocaleString('es-CO') ?? '0'}
                            pie={`de ${plan.agentes_incluidos} de tu plan`}
                            aviso={plan.se_paso_de?.includes('agentes')}
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
                            Tienes más {plan.se_paso_de.join(' y ')} de los que incluye tu plan. No se te
                            ha limitado nada —seguimos atendiendo a todos— pero conviene que hablemos
                            para ajustarlo.
                        </p>
                    )}
                </div>

                {/* El CRM, antes que las extensiones.
                    Esta sección no existía y la pantalla empezaba por «Incluido
                    en tu plan» con la lista de extensiones. Para un cliente de
                    Esencial eso era una sola línea —la firma del agente— y su
                    plan parecía vacío, cuando lo que tiene es el producto
                    entero. Lo que separa un plan de otro son las extensiones;
                    lo que paga es sobre todo esto. */}
                <section>
                    <h2 className="text-sm font-semibold text-foreground">
                        Tu CRM
                        <span className="ml-2 font-normal text-muted-foreground">
                            en todos los planes
                        </span>
                    </h2>
                    <div className="mt-3 rounded-xl border bg-card p-5">
                        <ul className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            {nucleo.map(linea => (
                                <li key={linea} className="flex items-start gap-2 text-sm text-foreground">
                                    <Check className="mt-0.5 size-3.5 shrink-0 text-success" />
                                    {linea}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                {/* Lo que añade su plan por encima del CRM. */}
                <section>
                    <h2 className="text-sm font-semibold text-foreground">
                        Extensiones de tu plan
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
                                    Escríbenos y lo activamos. Añadir el complemento no interrumpe nada de
                                    lo que ya usas ni cambia tu plan.
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

                {/* Para situarse. Sin precios, a propósito: dependen de lo
                    negociado y de si es cliente de Integra, y un número suelto
                    aquí acaba contradiciendo a un comercial. */}
                <section className="grid gap-5 sm:grid-cols-2">
                    <div>
                        <h2 className="text-sm font-semibold text-foreground">
                            Tamaño
                            <span className="ml-2 font-normal text-muted-foreground">agentes y contactos</span>
                        </h2>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {planes.map(p => (
                                <span
                                    key={p.slug}
                                    title={`${p.agentes} agentes · ${p.contactos.toLocaleString('es-CO')} contactos`}
                                    className={clsx(
                                        'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold',
                                        p.es_el_suyo
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-border text-muted-foreground'
                                    )}
                                >
                                    {p.es_el_suyo && <Check className="size-3.5" />}
                                    {p.nombre}
                                </span>
                            ))}
                        </div>
                    </div>

                    <div>
                        <h2 className="text-sm font-semibold text-foreground">
                            Inteligencia artificial
                            <span className="ml-2 font-normal text-muted-foreground">se añade aparte</span>
                        </h2>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {complementos.map(c => (
                                <span
                                    key={c.slug}
                                    className={clsx(
                                        'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold',
                                        c.es_el_suyo
                                            ? 'border-info bg-info/10 text-info'
                                            : 'border-border text-muted-foreground'
                                    )}
                                >
                                    {c.es_el_suyo && <Check className="size-3.5" />}
                                    {c.nombre}
                                    {c.slug !== 'ninguno' && <Sparkles className="size-3" />}
                                </span>
                            ))}
                        </div>
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
