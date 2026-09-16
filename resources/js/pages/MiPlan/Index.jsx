import { Head, Link } from '@inertiajs/react';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    Check, Lock, Sparkles, Users, Gift, ArrowRight, MessageSquare, Phone,
    TrendingUp, ShieldCheck,
} from 'lucide-react';
import { iconFor } from '@/pages/Extensions/icons';

/**
 * «Mi plan», tal y como lo ve el cliente.
 *
 * Deliberadamente no enseña precios ni costes: el precio depende del tramo, del
 * pago anual y de lo negociado, y un número suelto aquí acaba contradiciendo a
 * un comercial. Lo que sí enseña es qué tiene, cuánto le queda y qué se
 * desbloquea subiendo — que es la parte que decide.
 *
 * ## Cómo invita a subir, sin empujar
 *
 * Con sus propios números y no con un cartel. Los tres medidores —contactos,
 * agentes y líneas— dicen cuánto le queda de lo suyo, y cuando uno se llena la
 * pantalla nombra el plan concreto que le tocaría y qué ganaría con él. Un
 * «mejora tu plan» genérico se ignora; «vas por 2.800 de 3.000 contactos, el
 * Pro te da 10.000» se lee.
 *
 * Por eso el botón de hablar está siempre, y no sólo cuando hay algo bloqueado:
 * antes vivía al final de la lista de extensiones y quien ya las tenía todas
 * —justo el cliente que puede crecer— no veía ninguna forma de pedir nada.
 */
export default function MiPlan({ plan, uso_ia, extensiones, planes, complementos = [], nucleo = [] }) {
    const incluidas = extensiones.filter(e => e.en_plan);
    const bloqueadas = extensiones.filter(e => !e.en_plan);
    const sugerido = planes.find(p => p.es_el_sugerido);
    const elSuyo = planes.find(p => p.es_el_suyo);

    return (
        <>
            <Head title="Mi plan" />

            <div className="flex flex-col gap-7 p-6 lg:p-8 max-w-5xl">

                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Mi plan</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Lo que tienes contratado, cuánto llevas usado y qué puedes activar.
                        </p>
                    </div>

                    {/* Siempre visible. El cliente que más puede crecer es el que
                        ya lo tiene todo encendido, y a ése la pantalla no le
                        ofrecía ningún sitio donde preguntar. */}
                    <Button asChild variant="outline" className="gap-2">
                        <a href={CONTACTO}>
                            Hablar con nosotros <ArrowRight className="size-4" />
                        </a>
                    </Button>
                </div>

                {/* ── La cabecera: qué tienes contratado ───────────────────── */}
                <div className="overflow-hidden rounded-xl border bg-card">
                    <div className="flex flex-wrap items-center justify-between gap-4 border-b bg-primary/[0.07] px-6 py-5">
                        <div className="flex flex-wrap items-center gap-x-8 gap-y-3">
                            <div>
                                <p className="text-xs font-medium text-muted-foreground">Tu plan</p>
                                <p className="mt-0.5 text-2xl font-semibold tracking-tight text-foreground">
                                    {plan.plan_nombre}
                                </p>
                            </div>

                            <div className="h-10 w-px bg-border" />

                            <div>
                                <p className="text-xs font-medium text-muted-foreground">Inteligencia artificial</p>
                                <p className={clsx(
                                    'mt-0.5 flex items-center gap-1.5 text-2xl font-semibold tracking-tight',
                                    plan.tiene_ia ? 'text-foreground' : 'text-muted-foreground'
                                )}>
                                    {plan.tiene_ia && <Sparkles className="size-5 text-accent-foreground" />}
                                    {plan.ia_nombre}
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            {plan.incluido_en_integra && (
                                <Insignia icono={ShieldCheck} tono="success">
                                    Incluido con tu Integra
                                </Insignia>
                            )}
                            {plan.en_mes_gratis && (
                                <Insignia icono={Gift} tono="success">
                                    Cortesía hasta el {new Date(plan.gratis_hasta).toLocaleDateString('es-CO')}
                                </Insignia>
                            )}
                        </div>
                    </div>

                    {/* Los tres medidores. Antes eran dos cifras sueltas sin
                        barra: «2.800 de 3.000» obliga a dividir de cabeza para
                        saber si vas justo, y nadie divide. */}
                    <div className="grid gap-px bg-border sm:grid-cols-3">
                        <Medidor
                            icono={Users}
                            titulo="Contactos"
                            usado={plan.contactos_reales}
                            incluido={plan.contactos_incluidos}
                            pasado={plan.se_paso_de?.includes('contactos')}
                        />
                        <Medidor
                            icono={MessageSquare}
                            titulo="Agentes"
                            usado={plan.agentes_reales}
                            incluido={plan.agentes_incluidos}
                            pasado={plan.se_paso_de?.includes('agentes')}
                        />
                        <Medidor
                            icono={Phone}
                            titulo="Líneas de WhatsApp"
                            usado={plan.lineas_reales}
                            incluido={plan.lineas_incluidas}
                            pasado={plan.se_paso_de?.includes('líneas')}
                        />
                    </div>

                    {/* El crédito de IA, cuando lo hay. Va en su propia fila
                        porque no se mide en lo mismo que lo de arriba: aquello
                        es tamaño contratado, esto se gasta y se repone cada mes. */}
                    {plan.tiene_ia && (
                        <div className="border-t px-6 py-5">
                            <Medidor
                                icono={Sparkles}
                                titulo="Conversaciones con IA este mes"
                                usado={uso_ia.usadas}
                                incluido={uso_ia.incluidas}
                                pasado={uso_ia.exceso > 0}
                                ancho
                                nota={uso_ia.exceso > 0
                                    ? `Llevas ${uso_ia.exceso.toLocaleString('es-CO')} por encima. No se corta nada: se factura el exceso.`
                                    : 'Se repone el día 1 de cada mes.'}
                            />
                        </div>
                    )}
                </div>

                {/* ── La invitación, sacada de sus propios números ──────────── */}
                {plan.se_paso_del_tramo ? (
                    <Invitacion
                        tono="warning"
                        titulo={`Te has quedado corto en ${listar(plan.se_paso_de)}`}
                        texto={sugerido
                            ? `No hemos limitado nada: seguimos atendiendo a todos tus contactos. Con el plan ${sugerido.nombre} tendrías ${sugerido.agentes} agentes, ${sugerido.contactos.toLocaleString('es-CO')} contactos y ${sugerido.lineas} ${sugerido.lineas === 1 ? 'línea' : 'líneas'}.`
                            : 'No hemos limitado nada: seguimos atendiendo a todos tus contactos. Tu volumen se sale de los planes de catálogo, así que lo tuyo lo miramos caso a caso.'}
                        boton={sugerido ? `Quiero pasar a ${sugerido.nombre}` : 'Hablemos de mi caso'}
                        mensaje={sugerido
                            ? `Hola, en Mi plan me sale que me quedé corto de ${listar(plan.se_paso_de)}. Quiero pasar al plan ${sugerido.nombre}.`
                            : 'Hola, en Mi plan me sale que me quedé corto y mi volumen se sale del catálogo.'}
                    />
                ) : !plan.tiene_ia ? (
                    <Invitacion
                        tono="primary"
                        icono={Sparkles}
                        titulo="Tu CRM puede contestar solo"
                        texto="El complemento de IA se añade sobre el plan que ya tienes, sin cambiarlo y sin interrumpir nada de lo que usas. Es lo que enciende el resumen de conversaciones, el semáforo afinado con IA y, en el nivel completo, que la IA responda los chats y resuelva contra tu ERP."
                        boton="Quiero saber más de la IA"
                        mensaje="Hola, quiero saber más sobre el complemento de IA de Integra CRM."
                        detalle={complementos.filter(c => c.slug !== 'ninguno')}
                    />
                ) : bloqueadas.length > 0 ? (
                    <Invitacion
                        tono="primary"
                        icono={TrendingUp}
                        titulo={`Te faltan ${bloqueadas.length} ${bloqueadas.length === 1 ? 'extensión' : 'extensiones'}`}
                        texto="Subir de complemento no cambia tu plan ni interrumpe nada de lo que ya usas. Se enciende y ya está."
                        boton="Ver qué me falta"
                        mensaje="Hola, quiero activar las extensiones que no tengo incluidas en mi plan."
                    />
                ) : null}

                {/* ── El CRM, antes que las extensiones ────────────────────── */}
                <section>
                    <h2 className="text-sm font-semibold text-foreground">
                        Tu CRM
                        <span className="ml-2 font-normal text-muted-foreground">en todos los planes</span>
                    </h2>
                    <div className="mt-3 rounded-xl border bg-card p-5">
                        <ul className="grid gap-x-6 gap-y-2.5 sm:grid-cols-2">
                            {nucleo.map(linea => (
                                <li key={linea} className="flex items-start gap-2 text-sm text-foreground">
                                    <Check className="mt-0.5 size-3.5 shrink-0 text-success" />
                                    {linea}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                {/* ── Lo que añade su plan ─────────────────────────────────── */}
                <section>
                    <h2 className="text-sm font-semibold text-foreground">
                        Extensiones de tu plan
                        <span className="ml-2 font-normal text-muted-foreground">{incluidas.length}</span>
                    </h2>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        {incluidas.map(e => <Tarjeta key={e.slug} extension={e} />)}
                    </div>
                </section>

                {/* ── Y lo que no ──────────────────────────────────────────── */}
                {bloqueadas.length > 0 && (
                    <section>
                        <h2 className="text-sm font-semibold text-foreground">
                            Disponible con el complemento de IA
                            <span className="ml-2 font-normal text-muted-foreground">{bloqueadas.length}</span>
                        </h2>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            {bloqueadas.map(e => <Tarjeta key={e.slug} extension={e} bloqueada />)}
                        </div>
                    </section>
                )}

                {/* ── Dónde estás y qué hay por encima ─────────────────────── */}
                <section>
                    <h2 className="text-sm font-semibold text-foreground">
                        Los planes
                        <span className="ml-2 font-normal text-muted-foreground">
                            agentes, contactos y líneas
                        </span>
                    </h2>

                    {/* Una tabla y no tres pastillas con el tamaño escondido en
                        un `title`: lo que decide si te cambias es la diferencia
                        entre columnas, y para verla hay que poder compararlas. */}
                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                        {planes.map(p => (
                            <div
                                key={p.slug}
                                className={clsx(
                                    'rounded-xl border p-4',
                                    p.es_el_suyo && 'border-primary bg-primary/[0.07]',
                                    p.es_el_sugerido && 'border-warning bg-warning/[0.06]',
                                    !p.es_el_suyo && !p.es_el_sugerido && 'bg-card'
                                )}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-sm font-semibold text-foreground">{p.nombre}</p>
                                    {p.es_el_suyo && (
                                        <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-primary">
                                            <Check className="size-3.5" /> El tuyo
                                        </span>
                                    )}
                                    {p.es_el_sugerido && (
                                        <span className="text-[11px] font-semibold text-warning">
                                            El que te toca
                                        </span>
                                    )}
                                </div>
                                <dl className="mt-3 space-y-1.5 text-xs">
                                    <Renglon termino="Agentes" valor={p.agentes} />
                                    <Renglon termino="Contactos" valor={p.contactos.toLocaleString('es-CO')} />
                                    <Renglon termino="Líneas" valor={p.lineas} />
                                </dl>
                            </div>
                        ))}
                    </div>

                    <p className="mt-3 text-xs text-muted-foreground">
                        La inteligencia artificial se añade aparte, sobre cualquiera de los tres:{' '}
                        {complementos.filter(c => c.slug !== 'ninguno').map(c => c.nombre).join(' o ')}.
                        {elSuyo && ' Cambiar de plan no toca nada de lo que ya tienes configurado.'}
                    </p>
                </section>
            </div>
        </>
    );
}

/** El WhatsApp de ventas, con el mensaje ya escrito. */
const CONTACTO = 'https://wa.me/573181454747?text=Hola,%20quiero%20saber%20m%C3%A1s%20sobre%20los%20planes%20de%20Integra%20CRM';

const contactoCon = (mensaje) => `https://wa.me/573181454747?text=${encodeURIComponent(mensaje)}`;

/** «contactos y agentes», no «contactos, agentes». */
function listar(cosas = []) {
    if (cosas.length <= 1) return cosas[0] ?? '';

    return `${cosas.slice(0, -1).join(', ')} y ${cosas[cosas.length - 1]}`;
}

function Insignia({ icono: Icono, tono, children }) {
    return (
        <span className={clsx(
            'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium',
            tono === 'success' ? 'border-success/30 bg-success/10 text-success' : 'border-border text-muted-foreground'
        )}>
            <Icono className="size-3.5 shrink-0" />
            {children}
        </span>
    );
}

/**
 * Cuánto llevas de lo tuyo.
 *
 * La barra se pinta aunque te hayas pasado —tope al 100%— porque lo que
 * comunica ahí es «lleno», y el número de al lado dice cuánto te pasaste. Una
 * barra que se sale de su caja no dice ninguna de las dos cosas.
 */
function Medidor({ icono: Icono, titulo, usado, incluido, pasado, nota, ancho }) {
    const usados = Number(usado ?? 0);
    const tope = Number(incluido ?? 0);
    const porcentaje = tope > 0 ? Math.min(100, Math.round((usados / tope) * 100)) : 0;

    // El ámbar entra antes de agotarse: avisar cuando ya no queda nada es
    // avisar tarde, y el que decide con tiempo no se queda sin margen.
    const tono = pasado ? 'destructive' : porcentaje >= 80 ? 'warning' : 'primary';

    return (
        <div className={clsx('bg-card px-6 py-5', ancho && 'px-0 py-0')}>
            <div className="flex items-center gap-1.5">
                <Icono className={clsx(
                    'size-3.5',
                    tono === 'destructive' ? 'text-destructive' : tono === 'warning' ? 'text-warning' : 'text-primary'
                )} />
                <p className="text-xs font-medium text-muted-foreground">{titulo}</p>
            </div>

            <div className="mt-1.5 flex items-baseline gap-1.5">
                <span className="text-2xl font-semibold tabular-nums text-foreground">
                    {usados.toLocaleString('es-CO')}
                </span>
                <span className="text-sm text-muted-foreground">
                    de {tope.toLocaleString('es-CO')}
                </span>
            </div>

            <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-border">
                <div
                    className={clsx(
                        'h-full rounded-full transition-all',
                        tono === 'destructive' ? 'bg-destructive' : tono === 'warning' ? 'bg-warning' : 'bg-primary'
                    )}
                    style={{ width: `${Math.max(porcentaje, 2)}%` }}
                />
            </div>

            <p className={clsx(
                'mt-2 text-xs',
                tono === 'destructive' ? 'text-destructive' : 'text-muted-foreground'
            )}>
                {nota ?? (pasado
                    ? `${(usados - tope).toLocaleString('es-CO')} por encima de tu plan`
                    : `Te queda un ${100 - porcentaje} %`)}
            </p>
        </div>
    );
}

/**
 * La invitación a subir. Una sola, y la que toque.
 *
 * Tres avisos compitiendo —«te quedaste corto», «te falta IA», «te faltan
 * extensiones»— se leen como un anuncio y se saltan enteros. Se elige el que
 * corresponde a lo que de verdad le pasa a esta empresa, por orden de urgencia:
 * primero lo que ya se le quedó pequeño, luego lo que no tiene.
 */
function Invitacion({ tono, icono: Icono = TrendingUp, titulo, texto, boton, mensaje, detalle = [] }) {
    return (
        <div className={clsx(
            'rounded-xl border p-5',
            tono === 'warning' ? 'border-warning/40 bg-warning/[0.07]' : 'border-primary/40 bg-primary/[0.07]'
        )}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex min-w-0 flex-1 items-start gap-3">
                    <div className={clsx(
                        'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg',
                        tono === 'warning' ? 'bg-warning/15 text-warning' : 'bg-primary/15 text-primary'
                    )}>
                        <Icono className="size-4" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-sm font-semibold text-foreground">{titulo}</p>
                        <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{texto}</p>

                        {detalle.length > 0 && (
                            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                {detalle.map(nivel => (
                                    <div key={nivel.slug} className="rounded-lg border bg-card px-3 py-2.5">
                                        <p className="text-xs font-semibold text-foreground">{nivel.nombre}</p>
                                        <ul className="mt-1 space-y-0.5">
                                            {[...(nivel.extensiones ?? []), ...(nivel.flujos ?? [])].map(linea => (
                                                <li key={linea} className="flex items-start gap-1.5 text-[11px] text-muted-foreground">
                                                    <Check className="mt-0.5 size-2.5 shrink-0 text-success" />
                                                    {linea}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                <Button asChild className="shrink-0 gap-2">
                    <a href={contactoCon(mensaje)}>
                        {boton} <ArrowRight className="size-4" />
                    </a>
                </Button>
            </div>
        </div>
    );
}

function Renglon({ termino, valor }) {
    return (
        <div className="flex items-baseline justify-between gap-2">
            <dt className="text-muted-foreground">{termino}</dt>
            <dd className="font-medium tabular-nums text-foreground">{valor}</dd>
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
