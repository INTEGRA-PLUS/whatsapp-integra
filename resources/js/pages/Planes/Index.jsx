import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Check, Sparkles, ShieldCheck, ArrowRight, Users, Contact, Phone, Bot } from 'lucide-react';

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

            <div className="flex flex-col gap-7 p-6 lg:p-8 max-w-6xl">

                <div>
                    <h1 className="text-2xl font-semibold text-foreground">Planes</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Todo lo que hay, con sus precios, para que puedas comparar con lo que tienes.
                    </p>
                </div>

                {flash.success && <Aviso tono="success">{flash.success}</Aviso>}
                {flash.error && <Aviso tono="warning">{flash.error}</Aviso>}

                {/* Lo que tiene hoy, en una línea. Sin esto la tabla es una lista
                    de precios y no una comparación. */}
                <div className="rounded-xl border bg-card px-6 py-4">
                    <p className="text-xs font-medium text-muted-foreground">Hoy tienes</p>
                    <p className="mt-1 text-lg font-semibold text-foreground">
                        {suCrm?.nombre} {suIa && suIa.slug !== 'ninguno' ? <>+ {suIa.nombre}</> : <span className="font-normal text-muted-foreground">· sin complemento de IA</span>}
                    </p>
                    {actual.incluido_en_integra && (
                        <p className="mt-1.5 flex items-center gap-1.5 text-sm text-success">
                            <ShieldCheck className="size-4" />
                            Tu paquete de Integra cubre el CRM: de esta tabla sólo pagarías el complemento de IA.
                        </p>
                    )}
                </div>

                {/* ── Los planes de CRM: el tamaño ──────────────────────────── */}
                <section className="flex flex-col gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-foreground">El plan de CRM decide tu tamaño</h2>
                        <p className="text-sm text-muted-foreground">
                            Cuántos agentes, cuántos contactos y cuántas líneas de WhatsApp. No son límites duros:
                            no dejamos de atender a nadie porque tu empresa creció.
                        </p>
                    </div>

                    <div className="grid gap-4 md:grid-cols-3">
                        {crm.map(p => (
                            <Tarjeta
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
                            </Tarjeta>
                        ))}
                    </div>

                    {anual && (
                        <p className="text-xs text-muted-foreground">
                            Pagando al año se cobran {anual.mensualidades} mensualidades por {anual.meses} meses:
                            dos meses no se pagan.
                        </p>
                    )}
                </section>

                {/* ── El complemento: las funciones ─────────────────────────── */}
                <section className="flex flex-col gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-foreground">El complemento de IA decide qué se enciende</h2>
                        <p className="text-sm text-muted-foreground">
                            Se añade sobre el plan que ya tienes, sin cambiarlo. Es lo que se suma aparte en tu recibo.
                        </p>
                    </div>

                    <div className="grid gap-4 md:grid-cols-3">
                        {ia.map(p => (
                            <Tarjeta
                                key={p.slug}
                                nombre={p.nombre}
                                precio={p.precio}
                                esElSuyo={p.slug === actual.ia}
                                pidiendo={pidiendo === p.slug}
                                onPedir={p.slug === 'ninguno' ? null : () => pedir('ia', p.slug)}
                                icono={p.slug === 'ninguno' ? null : Sparkles}
                            >
                                {p.slug === 'ninguno' ? (
                                    <p className="text-sm text-muted-foreground">El CRM funciona entero sin IA. Esto es lo que se añade encima.</p>
                                ) : (
                                    <>
                                        {p.extensiones.map(e => <Renglon key={e} texto={e} />)}
                                        {p.flujos.map(f => <Renglon key={f} texto={f} />)}
                                    </>
                                )}
                            </Tarjeta>
                        ))}
                    </div>
                </section>

                {/* ── Lo que va en todos ────────────────────────────────────── */}
                {nucleo.length > 0 && (
                    <section className="rounded-xl border bg-card p-6">
                        <h2 className="text-base font-semibold text-foreground">Esto va en todos los planes</h2>
                        <div className="mt-3 grid gap-x-8 gap-y-2 sm:grid-cols-2">
                            {nucleo.map(n => (
                                <p key={n} className="flex items-start gap-2 text-sm text-muted-foreground">
                                    <Check className="mt-0.5 size-3.5 shrink-0 text-success" />
                                    {n}
                                </p>
                            ))}
                        </div>
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

/**
 * Una tarjeta del catálogo.
 *
 * El botón no dice «cambiar» sino «lo quiero»: no hay autoservicio y fingir que
 * lo hay es peor que no tenerlo. El que ya es tuyo no lleva botón.
 */
function Tarjeta({ nombre, precio, gratis, esElSuyo, esElSugerido, pidiendo, onPedir, icono: Icono, children }) {
    return (
        <div className={clsx(
            'flex flex-col rounded-xl border bg-card p-5',
            esElSuyo && 'border-primary/50 ring-1 ring-primary/20',
            esElSugerido && !esElSuyo && 'border-success/50'
        )}>
            <div className="flex items-start justify-between gap-2">
                <p className="flex items-center gap-1.5 font-semibold text-foreground">
                    {Icono && <Icono className="size-4 text-accent-foreground" />}
                    {nombre}
                </p>
                {esElSuyo && <span className="rounded-md bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary">El tuyo</span>}
                {esElSugerido && !esElSuyo && <span className="rounded-md bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success">Te encajaría</span>}
            </div>

            <p className="mt-2 flex items-baseline gap-1.5">
                {gratis && precio > 0 ? (
                    <>
                        <span className="text-2xl font-semibold tracking-tight text-success">Incluido</span>
                        <span className="text-xs text-muted-foreground line-through">${precio}</span>
                    </>
                ) : (
                    <>
                        <span className="text-2xl font-semibold tracking-tight text-foreground">${precio}</span>
                        <span className="text-xs text-muted-foreground">/mes</span>
                    </>
                )}
            </p>

            <div className="mt-4 flex flex-1 flex-col gap-1.5">{children}</div>

            {onPedir && !esElSuyo && (
                <Button variant="outline" size="sm" className="mt-5 gap-2" disabled={pidiendo} onClick={onPedir}>
                    {pidiendo ? 'Enviando…' : <>Lo quiero <ArrowRight className="size-3.5" /></>}
                </Button>
            )}
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
        <p className="flex items-start gap-2 text-sm text-muted-foreground">
            <Icono className="mt-0.5 size-3.5 shrink-0 text-success" />
            <span>
                {texto}
                {typeof tuyo === 'number' && (
                    <span className="text-muted-foreground/60"> · usas {tuyo.toLocaleString('es-CO')}</span>
                )}
            </span>
        </p>
    );
}

function Aviso({ tono, children }) {
    return (
        <div className={clsx(
            'rounded-lg border px-4 py-3 text-sm',
            tono === 'success' ? 'border-success/30 bg-success/10 text-success' : 'border-warning/30 bg-warning/10 text-warning'
        )}>
            {children}
        </div>
    );
}
