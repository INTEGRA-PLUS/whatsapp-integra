import { Head, Link } from '@inertiajs/react';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    Check, Lock, Sparkles, Users, Gift, ArrowRight, MessageSquare, Phone,
    TrendingUp, ShieldCheck, CalendarDays, AlertCircle, BadgeCheck,
} from 'lucide-react';
import { iconFor } from '@/pages/Extensions/icons';
import { ListaConChecks, Pastilla, Titulo } from '@/components/seccion';

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
 *
 * ## Cómo está compuesta (21-sep-2026)
 *
 * Era una columna de ocho cajas idénticas: mismo borde, mismo blanco, títulos
 * todos en `text-sm`. Con todo al mismo peso, la pantalla no decía por dónde
 * empezar y lo primero —cuál es tu plan— pesaba lo mismo que la última fila de
 * la tabla de recibos.
 *
 * Ahora la página **baja de temperatura**: el carné en navy arriba (el único
 * bloque con fondo de marca, para que el plan contratado sea lo que se ve al
 * entrar), después las tarjetas de uso, y de ahí para abajo contenido sobre el
 * fondo claro. Una sola superficie oscura por pantalla; dos compiten.
 *
 * Reglas de color que se siguen aquí y conviene no «arreglar»:
 *  - El verde de marca **no vale como texto** sobre claro (2.05:1). Para tinta
 *    verde va `text-accent-foreground`, que es el mismo tono bajado hasta 4.52:1.
 *    `bg-primary` sí, pero con `text-primary-foreground` (navy) encima.
 *  - El navy del carné es `bg-sidebar`, el mismo token que la barra lateral, que
 *    ya es navy en los dos temas. Así el bloque no necesita un color escrito a
 *    mano ni un caso especial para el tema oscuro.
 */
export default function MiPlan({ plan, uso_ia, extensiones, planes, complementos = [], nucleo = [], periodo = null, por_pagar = null, recibos = [] }) {
    const incluidas = extensiones.filter(e => e.en_plan);
    const bloqueadas = extensiones.filter(e => !e.en_plan);
    const sugerido = planes.find(p => p.es_el_sugerido);

    return (
        <>
            <Head title="Mi plan" />

            <div className="mx-auto flex max-w-6xl flex-col gap-9 p-6 lg:p-8">

                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-center gap-3.5">
                        <div className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-primary/15 text-accent-foreground">
                            <BadgeCheck className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight text-foreground">Mi plan</h1>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Lo que tienes contratado, cuánto llevas usado y qué puedes activar.
                            </p>
                        </div>
                    </div>

                    {/* Siempre visible. El cliente que más puede crecer es el que
                        ya lo tiene todo encendido, y a ése la pantalla no le
                        ofrecía ningún sitio donde preguntar. */}
                    <Button asChild variant="outline" className="gap-2">
                        <a href={CONTACTO}>
                            Hablar con nosotros <ArrowRight className="size-4" />
                        </a>
                    </Button>
                </header>

                <Carne plan={plan} periodo={periodo} porPagar={por_pagar} />

                {/* ── Cuánto llevas de lo tuyo ─────────────────────────────── */}
                <section className="space-y-4">
                    <Titulo eyebrow="Tu consumo">
                        Cuánto llevas usado
                    </Titulo>

                    {/* Tarjetas sueltas y no una rejilla con filetes: los tres
                        medidores miden cosas distintas y se leen de uno en uno.
                        Pegados con `gap-px` parecían columnas de una hoja de
                        cálculo, que es justo lo que no son. */}
                    <div className="grid gap-4 sm:grid-cols-3">
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

                        {/* El crédito de IA, cuando lo hay. Ocupa la fila entera
                            porque no se mide en lo mismo que lo de arriba:
                            aquello es tamaño contratado, esto se gasta y se
                            repone cada mes. */}
                        {plan.tiene_ia && (
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
                        )}
                    </div>
                </section>

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
                <section className="space-y-4">
                    <Titulo eyebrow="Incluido siempre" nota="Va en los tres planes, con o sin complemento de IA.">
                        Tu CRM
                    </Titulo>

                    <ListaConChecks items={nucleo} />
                </section>

                {/* ── Lo que añade su plan ─────────────────────────────────── */}
                <section className="space-y-4">
                    <Titulo eyebrow="Extensiones" contador={incluidas.length}>
                        Las que tienes incluidas
                    </Titulo>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {incluidas.map(e => <Tarjeta key={e.slug} extension={e} />)}
                    </div>
                </section>

                {/* ── Y lo que no ──────────────────────────────────────────── */}
                {bloqueadas.length > 0 && (
                    <section className="space-y-4">
                        <Titulo
                            eyebrow="Extensiones"
                            contador={bloqueadas.length}
                            nota="Se encienden con el complemento de IA, sin tocar tu plan."
                        >
                            Lo que todavía no tienes
                        </Titulo>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {bloqueadas.map(e => <Tarjeta key={e.slug} extension={e} bloqueada />)}
                        </div>
                    </section>
                )}

                {/* ── Dónde estás y qué hay por encima ─────────────────────── */}
                <section className="space-y-4">
                    <Titulo eyebrow="Comparativa" nota="Agentes, contactos y líneas de cada plan.">
                        Los planes
                    </Titulo>

                    {/* Tres tarjetas comparables y no tres pastillas con el
                        tamaño escondido en un `title`: lo que decide si te
                        cambias es la diferencia entre columnas, y para verla
                        hay que poder compararlas. */}
                    <div className="grid gap-4 pt-1.5 sm:grid-cols-3">
                        {planes.map(p => <TarjetaDePlan key={p.slug} p={p} tieneIa={plan.tiene_ia} />)}
                    </div>

                    {/* Las dos mitades de la decisión, dichas juntas porque por
                        separado ninguna se entiende: el complemento decide QUÉ
                        se enciende y el plan, CUÁNTO cabe. */}
                    <p className="max-w-3xl text-sm leading-relaxed text-muted-foreground">
                        La inteligencia artificial se añade aparte, sobre cualquiera de los tres:{' '}
                        {complementos.filter(c => c.slug !== 'ninguno').map(c => c.nombre).join(' o ')}.
                        El complemento decide <span className="font-semibold text-foreground">qué funciones</span>{' '}
                        se encienden; el plan, <span className="font-semibold text-foreground">cuántas
                        conversaciones</span> con IA te caben al mes —por eso el crédito sube con el
                        tamaño y no con el complemento.
                        {plan.tiene_ia
                            ? ' Cambiar de plan no toca nada de lo que ya tienes configurado.'
                            : ' Sin complemento contratado ese crédito no corre: no se gasta nada.'}
                    </p>
                </section>

                {/* Los recibos van al final: son el archivo de la pantalla, no
                    su titular. Arriba, entre el plan y lo que puede activar, una
                    tabla de doce filas cortaba la página en dos. */}
                <Facturacion recibos={recibos} />
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

/** «15 de octubre de 2026». Al mediodía, para que la zona horaria no reste un día. */
function fecha(iso, conAno = true) {
    if (!iso) return '';

    return new Date(`${iso}T12:00`).toLocaleDateString('es-CO', {
        day: 'numeric', month: 'long', ...(conAno ? { year: 'numeric' } : {}),
    });
}

/**
 * «del 15 de septiembre al 15 de octubre de 2026».
 *
 * El año sólo en el segundo cuando los dos caen en el mismo: repetirlo alarga la
 * frase sin decir nada, y es la línea que el cliente coteja con su factura.
 */
function rango(desde, hasta) {
    const mismoAno = desde?.slice(0, 4) === hasta?.slice(0, 4);

    return [fecha(desde, !mismoAno), fecha(hasta)];
}

/** «15 sep 2026», para la tabla, donde la frase larga no cabe. */
function fechaCorta(iso) {
    if (!iso) return '';

    return new Date(`${iso}T12:00`).toLocaleDateString('es-CO', {
        day: '2-digit', month: 'short', year: 'numeric',
    });
}

/**
 * El carné: qué tienes contratado y hasta cuándo.
 *
 * Es el único bloque con fondo de marca de la pantalla, y lo es a propósito: lo
 * primero que se ve al entrar tiene que ser el plan, no el encabezado. Antes
 * esto era una franja `bg-primary/[0.07]` —un verde tan lavado que en una
 * pantalla mal calibrada no se distinguía del blanco— con el nombre del plan en
 * el mismo cuerpo que cualquier otro dato.
 *
 * Va en `bg-sidebar` porque ese token ya es el navy de la marca en los dos
 * temas: escribir el color a mano obligaría a un caso especial para el oscuro, y
 * sobre él el verde `sidebar-accent-foreground` está medido a 8.8:1.
 */
function Carne({ plan, periodo, porPagar }) {
    const cobertura = datosCobertura(plan, periodo, porPagar);

    return (
        <section className="relative overflow-hidden rounded-3xl border border-sidebar-border/70 bg-sidebar text-sidebar-foreground shadow-lg">
            {/* Dos halos verdes desenfocados. Es lo único decorativo de la
                pantalla: sin ellos el navy es un rectángulo plano, y con más de
                dos empieza a parecer una plantilla comprada. */}
            <div aria-hidden className="pointer-events-none absolute -right-24 -top-32 size-80 rounded-full bg-sidebar-primary/20 blur-3xl" />
            <div aria-hidden className="pointer-events-none absolute -bottom-40 left-1/4 size-80 rounded-full bg-sidebar-primary/10 blur-3xl" />

            <div className={clsx('relative', cobertura && 'md:grid md:grid-cols-[1.15fr_1fr]')}>
                <div className={clsx(
                    'p-7 lg:p-8',
                    // Con cobertura al lado, la columna del plan es la corta de
                    // las dos: centrada se lee como una pareja, y arriba del
                    // todo deja un escalón de aire debajo del nombre.
                    cobertura && 'flex flex-col justify-center',
                    // Sin periodo emitido —el caso de todos hasta la primera
                    // emisión— no hay media columna que llenar, así que el carné
                    // se vuelve una banda: el plan a la izquierda y los
                    // distintivos a la derecha. En dos columnas, la mitad
                    // derecha se quedaba en un hueco navy vacío.
                    !cobertura && 'flex flex-wrap items-center justify-between gap-x-10 gap-y-5'
                )}>
                    <div>
                        <p className="text-[11px] font-black uppercase tracking-[0.2em] text-sidebar-foreground/50">
                            Tu plan
                        </p>
                        <h2 className="mt-2 text-4xl font-black tracking-tight text-sidebar-foreground">
                            {plan.plan_nombre}
                        </h2>
                    </div>

                    <div className={clsx('flex flex-wrap items-center gap-2', cobertura && 'mt-4')}>
                        <span className={clsx(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold',
                            plan.tiene_ia
                                ? 'border-sidebar-primary/40 bg-sidebar-primary/15 text-sidebar-accent-foreground'
                                : 'border-sidebar-border bg-sidebar-accent/25 text-sidebar-foreground/70'
                        )}>
                            <Sparkles className="size-3.5" />
                            {plan.ia_nombre}
                        </span>

                        {/* «Incluido con tu Integra», a secas y al lado de «IA
                            Completa», se leía como que la IA viene incluida. No:
                            Integra cubre el CRM, y la IA es justo lo único que sí
                            se le factura a un cliente de Integra. Se dice qué
                            cubre, no que cubre. */}
                        {plan.incluido_en_integra && (
                            <Insignia icono={ShieldCheck}>
                                {plan.tiene_ia ? 'CRM incluido con tu Integra' : 'Incluido con tu Integra'}
                            </Insignia>
                        )}
                        {plan.en_mes_gratis && (
                            <Insignia icono={Gift}>
                                Cortesía hasta el {new Date(plan.gratis_hasta).toLocaleDateString('es-CO')}
                            </Insignia>
                        )}
                    </div>
                </div>

                {cobertura && <Cobertura {...cobertura} />}
            </div>
        </section>
    );
}

/**
 * Hasta cuándo lo tiene cubierto, resuelto antes de pintar.
 *
 * Se saca aparte para que el carné sepa si tiene media columna que llenar: sin
 * periodo emitido —el caso de todos hasta la primera emisión— el bloque se
 * queda a una sola columna en vez de dejar un hueco navy vacío.
 *
 * Si no hay periodo cae a `suscripcion_hasta`, y si tampoco la hay devuelve
 * null: un recuadro que dice «sin periodo» alarma sin informar.
 */
function datosCobertura(plan, periodo, porPagar) {
    const hasta = periodo?.hasta ?? plan.suscripcion_hasta;

    if (!hasta && !porPagar) return null;

    return {
        plan,
        porPagar,
        hasta,
        desde: periodo?.desde ?? null,
        integra: periodo ? periodo.cubierto_por_integra : plan.incluido_en_integra,
        vencido: periodo ? !periodo.vigente : plan.suscripcion_vigente === false,
        dias: periodo?.dias ?? plan.dias_para_renovar,
    };
}

/**
 * La mitad derecha del carné.
 *
 * Con el **desde** y no sólo el hasta: una fecha suelta no se puede cotejar con
 * ninguna factura, y es justo lo que el cliente hace con este dato.
 *
 * Al de Integra se le dice por qué no paga aquí. Es la duda que llega por
 * WhatsApp —«¿esto me lo están cobrando aparte?»— y contestarla en la pantalla
 * ahorra la conversación entera.
 */
function Cobertura({ plan, porPagar, hasta, desde, integra, vencido, dias }) {
    return (
        <div className="border-t border-sidebar-border/60 p-7 md:border-l md:border-t-0 lg:p-8">
            <p className="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-[0.2em] text-sidebar-foreground/50">
                <CalendarDays className="size-3.5" /> Cobertura
            </p>

            <p className="mt-2 text-sm font-semibold text-sidebar-foreground">
                {integra
                    ? (plan.tiene_ia
                        ? 'Tu paquete de Integra cubre el CRM'
                        : 'Tu paquete de Integra cubre este servicio')
                    : 'Tu plan está activo'}
            </p>

            {hasta && (
                <>
                    <p className="mt-1.5 text-sm leading-relaxed text-sidebar-foreground/70">
                        {desde
                            ? <>Del <strong className="font-semibold text-sidebar-foreground">{rango(desde, hasta)[0]}</strong> al <strong className="font-semibold text-sidebar-foreground">{rango(desde, hasta)[1]}</strong></>
                            : <>Hasta el <strong className="font-semibold text-sidebar-foreground">{fecha(hasta)}</strong></>}
                    </p>

                    {/* Los días, en pastilla sólida y no en texto de color: sobre
                        el navy, el rojo y el ámbar de los tokens se quedan por
                        debajo de contraste como tinta, y con fondo propio pasan
                        de sobra. */}
                    {typeof dias === 'number' && (
                        <span className={clsx(
                            'mt-3 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold',
                            vencido
                                ? 'bg-destructive text-destructive-foreground'
                                : 'bg-sidebar-primary/15 text-sidebar-accent-foreground'
                        )}>
                            {vencido
                                ? `Venció hace ${Math.abs(dias)} ${Math.abs(dias) === 1 ? 'día' : 'días'}`
                                : `Quedan ${dias} ${dias === 1 ? 'día' : 'días'}`}
                        </span>
                    )}
                </>
            )}

            {/* Con complemento de IA, decir «no se te factura aparte» es falso:
                el CRM va dentro de Integra, pero la IA se cobra. Y lo contradecía
                la propia pantalla, que debajo enseñaba un cobro emitido y
                pendiente. */}
            {integra && (
                <p className="mt-3 text-xs leading-relaxed text-sidebar-foreground/60">
                    {plan.tiene_ia
                        ? <>El CRM va dentro de lo que ya pagas por Integra. <span className="text-sidebar-foreground/90">{plan.ia_nombre} se factura aparte</span>, y es lo que se cobra en este periodo.</>
                        : 'No se te factura aparte: va dentro de lo que ya pagas por Integra.'}
                </p>
            )}

            {porPagar && (
                <p className="mt-3 flex items-start gap-2 rounded-xl border border-warning/40 bg-warning/15 px-3 py-2.5 text-xs leading-relaxed text-sidebar-foreground">
                    <AlertCircle className="mt-px size-3.5 shrink-0 text-warning" />
                    <span>
                        Tienes un cobro emitido y pendiente de pago por el periodo
                        {' '}del {rango(porPagar.desde, porPagar.hasta)[0]} al {rango(porPagar.desde, porPagar.hasta)[1]}.
                    </span>
                </p>
            )}
        </div>
    );
}

/** Insignia del carné: sobre navy, así que sus colores son los de la barra. */
function Insignia({ icono: Icono, children }) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-sidebar-border bg-sidebar-accent/25 px-3 py-1.5 text-xs font-medium text-sidebar-foreground/80">
            <Icono className="size-3.5 shrink-0 text-sidebar-accent-foreground" />
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
        <div className={clsx(
            'rounded-2xl border bg-card p-5 shadow-sm transition-shadow hover:shadow-md',
            ancho && 'sm:col-span-3'
        )}>
            <div className="flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2.5">
                    <span className={clsx(
                        'flex size-9 shrink-0 items-center justify-center rounded-xl',
                        tono === 'destructive' ? 'bg-destructive/15 text-destructive'
                            : tono === 'warning' ? 'bg-warning/20 text-warning'
                            : 'bg-primary/20 text-accent-foreground'
                    )}>
                        <Icono className="size-4" />
                    </span>
                    <p className="truncate text-sm font-semibold text-foreground">{titulo}</p>
                </div>

                <span className={clsx(
                    'shrink-0 text-xs font-black tabular-nums',
                    tono === 'destructive' ? 'text-destructive'
                        : tono === 'warning' ? 'text-warning'
                        : 'text-muted-foreground'
                )}>
                    {porcentaje} %
                </span>
            </div>

            <div className="mt-4 flex items-baseline gap-1.5">
                <span className="text-3xl font-black tabular-nums tracking-tight text-foreground">
                    {usados.toLocaleString('es-CO')}
                </span>
                <span className="text-sm text-muted-foreground">
                    de {tope.toLocaleString('es-CO')}
                </span>
            </div>

            <div className="mt-3 h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className={clsx(
                        'h-full rounded-full transition-[width] duration-500',
                        tono === 'destructive' ? 'bg-destructive' : tono === 'warning' ? 'bg-warning' : 'bg-primary'
                    )}
                    style={{ width: `${Math.max(porcentaje, 2)}%` }}
                />
            </div>

            <p className={clsx(
                'mt-2.5 text-xs leading-relaxed',
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
    const warning = tono === 'warning';

    return (
        <div className={clsx(
            'rounded-2xl border p-6 shadow-sm',
            warning
                ? 'border-warning/40 bg-gradient-to-br from-warning/[0.14] via-warning/[0.05] to-transparent'
                : 'border-primary/40 bg-gradient-to-br from-primary/[0.16] via-primary/[0.05] to-transparent'
        )}>
            <div className="flex flex-wrap items-start justify-between gap-5">
                <div className="flex min-w-0 flex-1 items-start gap-4">
                    <div className={clsx(
                        'flex size-11 shrink-0 items-center justify-center rounded-2xl',
                        warning ? 'bg-warning/25 text-warning' : 'bg-primary/25 text-accent-foreground'
                    )}>
                        <Icono className="size-5" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-base font-black tracking-tight text-foreground">{titulo}</p>
                        <p className="mt-1.5 max-w-2xl text-sm leading-relaxed text-muted-foreground">{texto}</p>

                        {detalle.length > 0 && (
                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                {detalle.map(nivel => (
                                    <div key={nivel.slug} className="rounded-xl border bg-card p-4 shadow-sm">
                                        <p className="flex items-center gap-1.5 text-sm font-bold text-foreground">
                                            <Sparkles className="size-3.5 text-accent-foreground" />
                                            {nivel.nombre}
                                        </p>
                                        <ul className="mt-2 space-y-1.5">
                                            {[...(nivel.extensiones ?? []), ...(nivel.flujos ?? [])].map(linea => (
                                                <li key={linea} className="flex items-start gap-2 text-xs leading-relaxed text-muted-foreground">
                                                    <Check className="mt-0.5 size-3 shrink-0 text-accent-foreground" strokeWidth={3} />
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

/**
 * Un plan del catálogo.
 *
 * El suyo y el que le tocaría se marcan con anillo y cinta, no sólo con un
 * fondo tintado: en la pantalla de un portátil con poco brillo, un
 * `bg-primary/[0.07]` y un blanco son el mismo color.
 */
function TarjetaDePlan({ p, tieneIa }) {
    return (
        <div className={clsx(
            'relative flex flex-col rounded-2xl border bg-card p-5 shadow-sm transition-shadow hover:shadow-md',
            p.es_el_suyo && 'border-primary/50 ring-2 ring-primary/30',
            p.es_el_sugerido && 'border-warning/50 ring-2 ring-warning/30'
        )}>
            {(p.es_el_suyo || p.es_el_sugerido) && (
                <span className={clsx(
                    'absolute -top-2.5 left-5 inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider',
                    p.es_el_suyo ? 'bg-primary text-primary-foreground' : 'bg-warning text-warning-foreground'
                )}>
                    {p.es_el_suyo ? <><Check className="size-3" strokeWidth={3.5} /> El tuyo</> : 'El que te toca'}
                </span>
            )}

            <p className="text-base font-black tracking-tight text-foreground">{p.nombre}</p>

            <dl className="mt-4 space-y-2">
                <Renglon termino="Agentes" valor={p.agentes} />
                <Renglon termino="Contactos" valor={p.contactos.toLocaleString('es-CO')} />
                <Renglon termino="Líneas" valor={p.lineas} />
            </dl>

            {/* El crédito de IA, en su propio recuadro a propósito: no es tamaño
                contratado como lo de arriba, es lo que se gasta cada mes, y sólo
                corre si además hay complemento. Sin esta línea la comparativa no
                explicaba en qué se nota subir de plan a quien lo que quiere es la
                IA — que es todo el que mira esta pantalla dos veces. */}
            <div className="mt-4 rounded-xl bg-muted/60 px-3.5 py-3">
                <p className="flex items-center gap-1.5 text-[11px] font-semibold text-muted-foreground">
                    <Sparkles className={clsx('size-3', tieneIa ? 'text-accent-foreground' : 'text-muted-foreground/60')} />
                    Con complemento de IA
                </p>
                <p className="mt-1 flex items-baseline gap-1.5">
                    <span className="text-lg font-black tabular-nums tracking-tight text-foreground">
                        {p.credito_ia.toLocaleString('es-CO')}
                    </span>
                    <span className="text-xs text-muted-foreground">conversaciones / mes</span>
                </p>
            </div>
        </div>
    );
}

function Renglon({ termino, valor }) {
    return (
        <div className="flex items-baseline justify-between gap-2 border-b border-border/50 pb-2 last:border-0 last:pb-0">
            <dt className="text-xs text-muted-foreground">{termino}</dt>
            <dd className="text-sm font-bold tabular-nums text-foreground">{valor}</dd>
        </div>
    );
}

function Tarjeta({ extension, bloqueada }) {
    const Icono = iconFor(extension.icono);

    return (
        <div className={clsx(
            'flex items-start gap-3.5 rounded-2xl border p-4 transition-all',
            bloqueada
                ? 'border-dashed bg-muted/30'
                : 'bg-card shadow-sm hover:-translate-y-0.5 hover:shadow-md'
        )}>
            <div className={clsx(
                'flex size-10 shrink-0 items-center justify-center rounded-xl',
                bloqueada
                    ? 'bg-muted text-muted-foreground'
                    : extension.encendida ? 'bg-primary/20 text-accent-foreground' : 'bg-muted text-muted-foreground'
            )}>
                <Icono className="size-[18px]" />
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                    <p className={clsx(
                        'text-sm font-bold',
                        bloqueada ? 'text-muted-foreground' : 'text-foreground'
                    )}>
                        {extension.nombre}
                    </p>

                    {bloqueada ? (
                        <Pastilla tono="muted" icono={Lock}>{extension.plan_minimo}</Pastilla>
                    ) : extension.encendida ? (
                        <Pastilla tono="success" punto>Activa</Pastilla>
                    ) : (
                        <Pastilla tono="muted">{extension.instalada ? 'Apagada' : 'Sin instalar'}</Pastilla>
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
                        className="mt-2 inline-flex items-center gap-1 text-xs font-bold text-accent-foreground hover:underline"
                    >
                        {extension.instalada ? 'Encender' : 'Activar'} <ArrowRight className="size-3" />
                    </Link>
                )}
            </div>
        </div>
    );
}

/**
 * Lo que se le ha facturado, mes a mes.
 *
 * Antes no existía en ninguna parte del lado del cliente: la lista vivía sólo en
 * el panel maestro, así que para saber si su mes estaba cubierto tenía que
 * escribirnos.
 *
 * Aquí sí sale el importe, y no contradice que la pantalla no enseñe precios: un
 * precio de catálogo es una negociación abierta; un recibo es lo que ya se le
 * cobró, y esconderlo sólo consigue que lo pida por WhatsApp.
 *
 * Al cliente de Integra le sale «Incluido» en vez de un cero. Un cero sin
 * explicación se lee como un error de facturación.
 */
function Facturacion({ recibos = [] }) {
    if (recibos.length === 0) return null;

    return (
        <section className="space-y-4">
            <Titulo eyebrow="Historial" nota="Los periodos que se te han emitido, del más reciente al más antiguo.">
                Tu facturación
            </Titulo>

            <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                {/* En su propio contenedor con scroll: en un móvil la tabla no
                    cabe y sin esto se lleva la página entera de lado. */}
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[36rem] text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50 text-left text-[11px] font-black uppercase tracking-wider text-muted-foreground">
                                <th className="px-5 py-3 font-black">Periodo</th>
                                <th className="px-5 py-3 font-black">Concepto</th>
                                <th className="px-5 py-3 text-right font-black">Importe</th>
                                <th className="px-5 py-3 font-black">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recibos.map(r => (
                                <tr key={r.id} className="border-b transition-colors last:border-0 hover:bg-muted/30">
                                    <td className="whitespace-nowrap px-5 py-3.5 font-medium tabular-nums text-foreground">
                                        {fechaCorta(r.desde)} — {fechaCorta(r.hasta)}
                                    </td>
                                    <td className="px-5 py-3.5">
                                        <span className="text-muted-foreground">{r.concepto}</span>
                                        {/* De dónde sale el importe. Sin esto, «$49»
                                            junto a un nombre de plan no se puede
                                            explicar sin preguntarlo. */}
                                        {r.desglose?.length > 1 && (
                                            <span className="mt-1.5 flex flex-wrap gap-1.5">
                                                {r.desglose.map(l => (
                                                    <span
                                                        key={l.concepto}
                                                        className="whitespace-nowrap rounded-md bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground"
                                                    >
                                                        {l.concepto}: {l.importe === null ? l.nota : `$${l.importe}`}
                                                    </span>
                                                ))}
                                            </span>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-5 py-3.5 text-right">
                                        {r.estado === 'cubierto'
                                            ? <span className="text-sm text-muted-foreground">Incluido</span>
                                            : <span className="text-base font-black tabular-nums tracking-tight text-foreground">${r.importe_usd}</span>}
                                    </td>
                                    <td className="whitespace-nowrap px-5 py-3.5">
                                        <EstadoDelRecibo recibo={r} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    );
}

function EstadoDelRecibo({ recibo }) {
    const { estado, pagado_at } = recibo;

    if (estado === 'cubierto') {
        return <Pastilla tono="success" icono={ShieldCheck}>Cubierto por tu Integra</Pastilla>;
    }

    if (estado === 'pagado') {
        return (
            <Pastilla tono="success" icono={Check}>
                Pagado{pagado_at ? ` el ${fechaCorta(pagado_at)}` : ''}
            </Pastilla>
        );
    }

    if (estado === 'anulado') {
        return <Pastilla tono="muted">Anulado</Pastilla>;
    }

    return <Pastilla tono="warning" icono={AlertCircle}>Pendiente de pago</Pastilla>;
}

MiPlan.layout = page => <AppLayout>{page}</AppLayout>;
