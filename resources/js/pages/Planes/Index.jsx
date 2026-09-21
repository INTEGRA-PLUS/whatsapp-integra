import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { ListaConChecks, Titulo } from '@/components/seccion';
import {
    Check, Sparkles, ShieldCheck, ArrowRight, Users, Contact, Phone, Bot,
    Table2, Percent, CircleCheck, CircleAlert,
} from 'lucide-react';

/**
 * El catálogo entero, para comparar y para pedir el cambio.
 *
 * «Mi plan» cuenta lo que tienes; esto cuenta lo que hay. Son dos preguntas y
 * meterlas en la misma pantalla obligaba a elegir entre enseñar tu consumo o
 * enseñar la tabla.
 *
 * ## Aquí sí hay precios, y en «Mi plan» no
 *
 * No es una incoherencia. Un número suelto junto a tu plan actual contradice lo
 * que negoció un comercial; una tabla de catálogo se lee como precio de lista, y
 * sin ella no se puede comparar nada — que es a lo que se entra aquí.
 *
 * ## Las dos tablas están separadas a propósito
 *
 * El plan de CRM decide el **tamaño** —agentes, contactos, líneas— y el
 * complemento de IA decide **qué funciones** se encienden. Mezclarlas es lo que
 * obligaba a subir de plan entero para tener una función con IA.
 *
 * ## Y el cliente de Integra ve su CRM en cero
 *
 * Porque lo paga su ERP. Sin eso, leer «Pro $59» cuando llevas dos años sin
 * pagarlo se entiende como una subida de precio.
 *
 * ## Cómo está compuesta (21-sep-2026)
 *
 * Es la pantalla hermana de «Mi plan» y se rehízo con ella, con las mismas
 * piezas (`@/components/seccion`) y el mismo orden de lectura: la banda navy
 * arriba dice dónde estás, y a partir de ahí todo es catálogo sobre fondo
 * claro. Antes las dos pantallas se parecían sólo de lejos: el mismo bloque
 * —«esto va en todos los planes»— era aquí una lista gris dentro de una caja y
 * allí una rejilla de fichas.
 *
 * Lo que decide una pantalla de precios es el precio, así que es lo único que
 * va en cuerpo grande: `$29` en `text-4xl font-black`, y todo lo demás por
 * debajo. Antes el precio y el nombre del plan pesaban lo mismo.
 */
export default function Planes({ actual, crm, ia, ciclos, nucleo = [] }) {
    const { flash = {} } = usePage().props;
    const [pidiendo, setPidiendo] = useState(null);

    const anual = ciclos.find(c => c.slug === 'anual');
    const suCrm = crm.find(p => p.slug === actual.crm);
    const suIa = ia.find(p => p.slug === actual.ia);

    const pedir = (campo, slug) => {
        setPidiendo(slug);
        router.post(route('planes.solicitar'), { [campo]: slug }, {
            preserveScroll: true,
            onFinish: () => setPidiendo(null),
        });
    };

    return (
        <>
            <Head title="Planes" />

            <div className="mx-auto flex max-w-6xl flex-col gap-9 p-6 lg:p-8">

                <header className="flex items-center gap-3.5">
                    <div className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-primary/15 text-accent-foreground">
                        <Table2 className="size-6" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-foreground">Planes</h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Todo lo que hay, con sus precios, para que puedas comparar con lo que tienes.
                        </p>
                    </div>
                </header>

                {flash.success && <Aviso tono="success">{flash.success}</Aviso>}
                {flash.error && <Aviso tono="warning">{flash.error}</Aviso>}

                <HoyTienes actual={actual} suCrm={suCrm} suIa={suIa} />

                {/* ── Los planes de CRM: el tamaño ──────────────────────────── */}
                <section className="space-y-4">
                    <Titulo
                        eyebrow="El tamaño"
                        nota="Cuántos agentes, cuántos contactos y cuántas líneas de WhatsApp. No son límites duros: no dejamos de atender a nadie porque tu empresa creció."
                    >
                        El plan de CRM
                    </Titulo>

                    {/* El descuento del anual estaba en gris pequeño al pie de la
                        tabla, que es donde va lo que no importa. Es un argumento
                        de venta: sube y se dice en verde. */}
                    {anual && anual.meses > anual.mensualidades && (
                        <p className="inline-flex items-center gap-2 rounded-full border border-success/30 bg-success/10 px-3.5 py-1.5 text-xs font-semibold text-success">
                            <Percent className="size-3.5 shrink-0" />
                            Pagando al año se cobran {anual.mensualidades} mensualidades por {anual.meses} meses:
                            {' '}{mesesGratis(anual)} no se {anual.meses - anual.mensualidades === 1 ? 'paga' : 'pagan'}.
                        </p>
                    )}

                    <div className="grid gap-4 pt-1.5 md:grid-cols-3">
                        {crm.map(p => (
                            <TarjetaDePrecio
                                key={p.slug}
                                nombre={p.nombre}
                                precio={p.precio}
                                gratis={actual.incluido_en_integra}
                                esElSuyo={p.slug === actual.crm}
                                esElSugerido={p.slug === actual.sugerido}
                                pidiendo={pidiendo === p.slug}
                                onPedir={() => pedir('crm', p.slug)}
                            >
                                <Renglon icono={Users} texto={`${p.agentes} ${p.agentes === 1 ? 'agente' : 'agentes'}`} tuyo={actual.agentes} />
                                <Renglon icono={Contact} texto={`${p.contactos.toLocaleString('es-CO')} contactos`} tuyo={actual.contactos} />
                                <Renglon icono={Phone} texto={`${p.lineas} ${p.lineas === 1 ? 'línea' : 'líneas'} de WhatsApp`} tuyo={actual.lineas} />
                                <Renglon icono={Bot} texto={`${p.credito_ia.toLocaleString('es-CO')} conversaciones con IA al mes`} />
                            </TarjetaDePrecio>
                        ))}
                    </div>
                </section>

                {/* ── El complemento: las funciones ─────────────────────────── */}
                <section className="space-y-4">
                    <Titulo
                        eyebrow="Las funciones"
                        nota="Se añade sobre el plan que ya tienes, sin cambiarlo. Es lo que se suma aparte en tu recibo."
                    >
                        El complemento de IA
                    </Titulo>

                    <div className="grid gap-4 pt-1.5 md:grid-cols-3">
                        {ia.map(p => (
                            <TarjetaDePrecio
                                key={p.slug}
                                nombre={p.nombre}
                                precio={p.precio}
                                esElSuyo={p.slug === actual.ia}
                                pidiendo={pidiendo === p.slug}
                                onPedir={p.slug === 'ninguno' ? null : () => pedir('ia', p.slug)}
                                icono={p.slug === 'ninguno' ? null : Sparkles}
                                // «Sin IA» no se compra: es la casilla de no
                                // tener complemento. Con el mismo borde y la
                                // misma sombra que las otras dos parecía una
                                // opción más del catálogo.
                                apagada={p.slug === 'ninguno'}
                            >
                                {p.slug === 'ninguno' ? (
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        El CRM funciona entero sin IA. Esto es lo que se añade encima.
                                    </p>
                                ) : (
                                    <>
                                        {p.extensiones.map(e => <Renglon key={e} texto={e} />)}
                                        {p.flujos.map(f => <Renglon key={f} texto={f} />)}
                                    </>
                                )}
                            </TarjetaDePrecio>
                        ))}
                    </div>
                </section>

                {/* ── Lo que va en todos ────────────────────────────────────── */}
                {nucleo.length > 0 && (
                    <section className="space-y-4">
                        <Titulo eyebrow="Incluido siempre" nota="Con cualquier plan y con o sin complemento de IA.">
                            Esto va en todos
                        </Titulo>
                        <ListaConChecks items={nucleo} />
                    </section>
                )}

                <p className="text-xs text-muted-foreground">
                    Precios en dólares, por mes. Pedir un cambio no lo aplica: te escribimos para cerrarlo.
                </p>
            </div>
        </>
    );
}

Planes.layout = page => <AppLayout>{page}</AppLayout>;

/** «dos meses», «un mes»: el descuento dicho en meses y no en una resta. */
function mesesGratis(anual) {
    const meses = anual.meses - anual.mensualidades;

    return meses === 1 ? 'un mes' : `${meses} meses`;
}

/**
 * Dónde estás hoy, antes de la tabla.
 *
 * Sin esta banda el catálogo es una lista de precios y no una comparación: lo
 * que convierte «Pro $59» en una decisión es saber que estás en el Básico.
 *
 * Va en navy —`bg-sidebar`, el mismo token que la barra lateral, que ya es navy
 * en los dos temas— para que sea la hermana del carné de «Mi plan». Es la única
 * superficie oscura de la pantalla: el protagonista aquí es el precio, así que
 * esto es una banda y no una cabecera de cuerpo entero.
 */
function HoyTienes({ actual, suCrm, suIa }) {
    const conIa = suIa && suIa.slug !== 'ninguno';

    return (
        <section className="relative overflow-hidden rounded-3xl border border-sidebar-border/70 bg-sidebar px-7 py-6 text-sidebar-foreground shadow-lg lg:px-8">
            <div aria-hidden className="pointer-events-none absolute -right-24 -top-32 size-72 rounded-full bg-sidebar-primary/20 blur-3xl" />

            <div className="relative flex flex-wrap items-center justify-between gap-x-10 gap-y-4">
                <div>
                    <p className="text-[11px] font-black uppercase tracking-[0.2em] text-sidebar-foreground/50">
                        Hoy tienes
                    </p>
                    <p className="mt-1.5 text-2xl font-black tracking-tight text-sidebar-foreground">
                        {suCrm?.nombre}
                        {conIa
                            ? <span className="text-sidebar-accent-foreground"> + {suIa.nombre}</span>
                            : <span className="font-medium text-sidebar-foreground/50"> · sin complemento de IA</span>}
                    </p>
                </div>

                {/* Al cliente de Integra hay que decírselo antes de que lea la
                    tabla: ver «Pro $59» cuando llevas dos años sin pagarlo se
                    entiende como una subida de precio. */}
                {actual.incluido_en_integra && (
                    <p className="flex max-w-sm items-start gap-2.5 rounded-xl border border-sidebar-border bg-sidebar-accent/25 px-3.5 py-2.5 text-xs leading-relaxed text-sidebar-foreground/80">
                        <ShieldCheck className="mt-px size-4 shrink-0 text-sidebar-accent-foreground" />
                        Tu paquete de Integra cubre el CRM: de esta tabla sólo pagarías el complemento de IA.
                    </p>
                )}
            </div>
        </section>
    );
}

/**
 * Una tarjeta del catálogo.
 *
 * El botón no dice «cambiar» sino «lo quiero»: no hay autoservicio y fingir que
 * lo hay es peor que no tenerlo.
 *
 * El que ya es tuyo tampoco se queda sin pie. Antes no pintaba nada ahí y la
 * tarjeta del plan actual quedaba más corta que sus vecinas, justo la que
 * tendría que ser la referencia para comparar.
 */
function TarjetaDePrecio({
    nombre, precio, gratis, esElSuyo, esElSugerido, pidiendo, onPedir,
    icono: Icono, apagada, children,
}) {
    const destacada = esElSugerido && !esElSuyo;

    return (
        <div className={clsx(
            'relative flex flex-col rounded-2xl border p-6 transition-shadow',
            apagada
                ? 'border-dashed bg-muted/30'
                : 'bg-card shadow-sm hover:shadow-md',
            esElSuyo && 'border-primary/50 ring-2 ring-primary/30',
            destacada && 'border-warning/50 ring-2 ring-warning/30'
        )}>
            {/* Cinta y no un fondo tintado: en la pantalla de un portátil con
                poco brillo, un `bg-primary/10` y un blanco son el mismo color. */}
            {(esElSuyo || destacada) && (
                <span className={clsx(
                    'absolute -top-2.5 left-6 inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider',
                    esElSuyo ? 'bg-primary text-primary-foreground' : 'bg-warning text-warning-foreground'
                )}>
                    {esElSuyo ? <><Check className="size-3" strokeWidth={3.5} /> El tuyo</> : 'Te encajaría'}
                </span>
            )}

            <p className="flex items-center gap-1.5 text-base font-black tracking-tight text-foreground">
                {Icono && <Icono className="size-4 text-accent-foreground" />}
                {nombre}
            </p>

            <p className="mt-3 flex items-baseline gap-2">
                {gratis && precio > 0 ? (
                    <>
                        <span className="text-3xl font-black tracking-tight text-success">Incluido</span>
                        <span className="text-sm text-muted-foreground line-through tabular-nums">${precio}</span>
                    </>
                ) : (
                    <>
                        {/* El «$0» de «Sin IA» no compite con los precios de
                            verdad: en el mismo cuerpo que un $49, la casilla de
                            no tener complemento se leía como la oferta de la
                            fila. */}
                        <span className={clsx(
                            'font-black tabular-nums tracking-tight',
                            apagada ? 'text-2xl text-muted-foreground' : 'text-4xl text-foreground'
                        )}>
                            ${precio}
                        </span>
                        <span className="text-sm text-muted-foreground">/mes</span>
                    </>
                )}
            </p>

            <div className="mt-5 flex flex-1 flex-col gap-2.5">{children}</div>

            {esElSuyo ? (
                <p className="mt-6 flex items-center justify-center gap-1.5 rounded-xl border border-primary/30 bg-primary/10 py-2.5 text-xs font-bold text-accent-foreground">
                    <Check className="size-3.5" strokeWidth={3} /> Es el que tienes
                </p>
            ) : onPedir ? (
                <Button className="mt-6 w-full gap-2" disabled={pidiendo} onClick={onPedir}>
                    {pidiendo ? 'Enviando…' : <>Lo quiero <ArrowRight className="size-4" /></>}
                </Button>
            ) : null}
        </div>
    );
}

/**
 * Una línea de lo que incluye.
 *
 * Cuando se le pasa `tuyo`, además dice en cuánto vas. Es lo que convierte
 * «10.000 contactos» en una razón para moverte o para quedarte.
 */
function Renglon({ icono: Icono = Check, texto, tuyo }) {
    return (
        <p className="flex items-start gap-2.5 text-sm leading-snug text-foreground">
            <Icono className="mt-0.5 size-4 shrink-0 text-accent-foreground" />
            <span>
                {texto}
                {typeof tuyo === 'number' && (
                    <span className="text-muted-foreground"> · usas {tuyo.toLocaleString('es-CO')}</span>
                )}
            </span>
        </p>
    );
}

function Aviso({ tono, children }) {
    const Icono = tono === 'success' ? CircleCheck : CircleAlert;

    return (
        <div className={clsx(
            'flex items-start gap-2.5 rounded-xl border px-4 py-3 text-sm leading-relaxed',
            tono === 'success'
                ? 'border-success/30 bg-success/10 text-success'
                : 'border-warning/40 bg-warning/10 text-warning'
        )}>
            <Icono className="mt-0.5 size-4 shrink-0" />
            <span>{children}</span>
        </div>
    );
}
