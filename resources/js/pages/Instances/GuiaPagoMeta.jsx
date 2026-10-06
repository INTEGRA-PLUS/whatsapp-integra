import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Aviso, Paso, useAvance } from '@/components/guia';
import CabeceraModulo from '@/components/cabecera-modulo';
import { Button } from '@/components/ui/button';
import {
    CreditCard, ExternalLink, CircleCheck, CircleAlert, Loader2, RefreshCw, ShieldCheck, Receipt, Quote, BarChart3, Building2,
} from 'lucide-react';

/**
 * Activar el pago en Meta, paso a paso.
 *
 * ## Qué problema resuelve
 *
 * El 30-sep-2026 las facturas de JHeda Comunicaciones empezaron a volver con
 * «your WhatsApp Business account currency is not configured». En el CRM sólo
 * se veía «Fallido» en cada mensaje y nadie en la empresa sabía que tenía que ir
 * a Meta a poner una tarjeta.
 *
 * ## Por qué no lo hacemos nosotros
 *
 * El cobro de Meta va directo a la tarjeta del cliente: somos Tech Provider, no
 * BSP, y Meta no da ninguna API para asociar un método de pago a una cuenta
 * ajena. Lo único que está en nuestra mano es detectarlo —el CRM ya lo hace— y
 * llevar al cliente hasta la pantalla exacta. El enlace del botón es el que
 * trae el propio error de Meta: abre el asistente de esa cuenta con el
 * portafolio ya elegido.
 *
 * Sin capturas a propósito: el centro de facturación de Meta cambia de aspecto
 * cada pocos meses, y una captura vieja confunde más que un texto que nombra el
 * botón.
 */

const TOTAL = 5;

const EN_META = (
    <span className="inline-flex items-center gap-1 rounded bg-info/15 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-info">
        Panel de Meta
    </span>
);

const EN_CRM = (
    <span className="inline-flex items-center gap-1 rounded bg-primary/15 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-accent-foreground">
        Aquí en el CRM
    </span>
);

export default function GuiaPagoMeta({ lineas = [], elegida = null }) {
    const { paso, reiniciar, completados, porcentaje, terminado } = useAvance('guia-pago-meta:pasos-hechos', TOTAL);

    const conProblema = lineas.filter(l => l.problema);
    const [lineaId, setLineaId] = useState(elegida ?? conProblema[0]?.id ?? lineas[0]?.id ?? null);
    const linea = lineas.find(l => l.id === lineaId) ?? null;
    const consumo = useConsumo(linea?.waba_id ? linea.id : null);
    const portafolio = consumo.datos?.portafolio;

    return (
        <>
            <Head title="Activar el pago en Meta" />

            <div className="mx-auto flex max-w-3xl flex-col gap-5 p-4 pb-24 sm:p-6">
                <CabeceraModulo
                    icono={CreditCard}
                    volver="/instances"
                    titulo="Activar el pago en Meta"
                    descripcion="Meta cobra cada plantilla que envías (facturas, avisos, campañas) directamente a tu tarjeta. Sin tarjeta ni moneda configuradas en tu cuenta de WhatsApp, rechaza los envíos."
                />

                {linea?.problema ? (
                    <EstadoDeLaLinea linea={linea} />
                ) : conProblema.length > 0 ? (
                    <Aviso tono="alto" titulo="Tus mensajes no están saliendo">
                        <p>
                            Meta está rechazando los envíos de{' '}
                            <strong>{conProblema.map(l => `${l.nombre}${l.numero ? ` (${l.numero})` : ''}`).join(', ')}</strong>.
                            Elige la línea abajo para ver el motivo.
                        </p>
                    </Aviso>
                ) : (
                    <Aviso tono="bien" titulo="Tus líneas no tienen problemas de pago ahora mismo">
                        <p>
                            Esta guía queda aquí por si Meta rechaza un envío por falta de tarjeta. Si en
                            algún momento ves la alerta roja arriba de la pantalla, vuelve aquí.
                        </p>
                    </Aviso>
                )}

                {linea?.waba_id && <Consumo linea={linea} {...consumo} />}

                {/* ── Lo que hay que entender antes de tocar nada ───────────── */}
                <section className="grid gap-3 sm:grid-cols-3">
                    <Dato icono={Receipt} titulo="Quién cobra">
                        Meta, directo a tu tarjeta. Integra no cobra ni ve este dinero.
                    </Dato>
                    <Dato icono={CreditCard} titulo="Qué cobra">
                        Cada plantilla: facturas, recordatorios, campañas. En Colombia, unos pocos pesos por mensaje de Utilidad.
                    </Dato>
                    <Dato icono={ShieldCheck} titulo="Qué es gratis">
                        Responder a un cliente que te escribió en las últimas 24 horas.
                    </Dato>
                </section>

                {/* ── Qué línea ────────────────────────────────────────────── */}
                {lineas.length > 1 && (
                    <section className="flex flex-col gap-2 rounded-xl border bg-card p-4">
                        <p className="text-[13px] font-semibold text-foreground">¿Qué línea vas a arreglar?</p>
                        <div className="flex flex-col gap-2">
                            {lineas.map(l => (
                                <label
                                    key={l.id}
                                    className={`flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm transition-colors ${
                                        l.id === lineaId ? 'border-primary/50 bg-primary/5' : 'hover:bg-muted/40'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="linea"
                                        checked={l.id === lineaId}
                                        onChange={() => setLineaId(l.id)}
                                        className="accent-primary"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="font-semibold text-foreground">{l.nombre}</span>
                                        {l.numero && <span className="ml-2 tabular-nums text-muted-foreground">{l.numero}</span>}
                                    </span>
                                    {l.problema
                                        ? <span className="rounded-full bg-destructive/10 px-2 py-0.5 text-[11px] font-bold text-destructive">{ETIQUETA[l.problema] ?? 'Sin pago'}</span>
                                        : <span className="rounded-full bg-success/15 px-2 py-0.5 text-[11px] font-bold text-success">Al día</span>}
                                </label>
                            ))}
                        </div>
                    </section>
                )}

                {/* ── Los pasos ────────────────────────────────────────────── */}
                <div className="flex items-center justify-between gap-3">
                    <p className="text-[13px] text-muted-foreground">
                        {terminado ? 'Terminaste la guía.' : `${completados} de ${TOTAL} pasos · ${porcentaje}%`}
                    </p>
                    {completados > 0 && (
                        <button type="button" onClick={reiniciar} className="text-[12.5px] font-semibold text-muted-foreground hover:text-foreground">
                            Empezar de nuevo
                        </button>
                    )}
                </div>

                <div className="flex flex-col gap-3">
                    <Paso {...paso(1)} titulo="Ten a mano quién administra tu negocio en Meta" etiqueta={EN_META}>
                        <p>
                            Solo puede hacerlo alguien con <strong>control total</strong> del portafolio
                            comercial de Meta de tu empresa: normalmente quien creó la cuenta de WhatsApp
                            Business o el Facebook de la empresa.
                        </p>
                        <p>Necesitas también:</p>
                        <ul className="ml-4 list-disc space-y-1">
                            <li>Una <strong>tarjeta de crédito o débito</strong> a nombre de la empresa o de su representante.</li>
                            <li>
                                Que la tarjeta tenga <strong>habilitadas las compras por internet internacionales</strong>.
                                Es lo que más falla en Colombia: si el banco las tiene bloqueadas, Meta la rechaza sin explicar por qué.
                            </li>
                            <li>El <strong>NIT</strong> y la dirección de la empresa, para los recibos de Meta.</li>
                        </ul>
                    </Paso>

                    <Paso {...paso(2)} titulo="Abre la facturación de tu cuenta de WhatsApp" etiqueta={EN_META}
                        ruta="Configuración del negocio › Facturación y pagos › Cuentas › tu cuenta de WhatsApp Business">
                        <p>
                            El botón te lleva directo a la cuenta de {linea ? <strong>{linea.nombre}</strong> : 'tu línea'}.
                            Se abre en otra pestaña: deja esta abierta para volver al final.
                        </p>
                        {linea && (
                            <a href={linea.enlace} target="_blank" rel="noopener noreferrer">
                                <Button className="gap-2 bg-[#1877f2] text-white hover:bg-[#166fe0]">
                                    <ExternalLink className="size-4" /> Abrir la facturación en Meta
                                </Button>
                            </a>
                        )}
                        {portafolio ? (
                            <p>
                                Si Meta te pide elegir un portafolio, elige <strong>{portafolio.nombre ?? portafolio.id}</strong>{' '}
                                <span className="tabular-nums text-muted-foreground">(ID {portafolio.id})</span>: es el que tiene esta
                                línea y al que Meta le cobra. Si no te aparece o te dice que no tienes acceso, no eres
                                administrador de ese portafolio: vuelve al paso 1.
                            </p>
                        ) : (
                            <p>
                                Si Meta te pide elegir un portafolio, elige el de tu empresa, el que tiene tu
                                número de WhatsApp. Si te dice que no tienes acceso, no eres administrador: vuelve al paso 1.
                            </p>
                        )}
                    </Paso>

                    <Paso {...paso(3)} titulo="Elige el país y la moneda" etiqueta={EN_META}>
                        <p>
                            Si la cuenta no tiene moneda, Meta te abre un asistente. Elige <strong>Colombia</strong> como
                            país y la moneda que te ofrezca.
                        </p>
                        <Aviso tono="alto" titulo="La moneda no se puede cambiar después">
                            <p>
                                Una vez guardada, cambiarla obliga a rehacer la cuenta de WhatsApp. Si dudas,
                                pregúntale a tu contador antes de confirmar.
                            </p>
                        </Aviso>
                    </Paso>

                    <Paso {...paso(4)} titulo="Añade la tarjeta y tus datos de facturación" etiqueta={EN_META}>
                        <p>
                            En la misma pantalla, pulsa <strong>Añadir método de pago</strong> y escribe los datos de la
                            tarjeta. Meta puede hacer un cobro pequeño de verificación que luego devuelve.
                        </p>
                        <p>
                            Completa también los <strong>datos de la empresa</strong>: razón social, NIT, dirección y el
                            correo donde quieres recibir los recibos de Meta. Es lo que te permite llevar esos
                            cobros a tu contabilidad.
                        </p>
                        <Aviso tono="info" titulo="Si ya tenías tarjeta y aun así falla">
                            <p>
                                Mira si está <strong>vencida</strong>, si Meta la marca como <strong>rechazada</strong> o si hay un
                                saldo pendiente sin pagar. Cualquiera de las tres bloquea los envíos igual que no tenerla.
                            </p>
                        </Aviso>
                    </Paso>

                    <Paso {...paso(5)} titulo="Vuelve aquí y comprueba" etiqueta={EN_CRM}>
                        <p>Cuando Meta guarde la tarjeta, pulsa el botón. Le preguntamos a Meta si ya te deja enviar.</p>
                        {linea && <Comprobar linea={linea} />}
                        <p>
                            Los mensajes que fallaron mientras tanto <strong>no se reenvían solos</strong>. Reenvíalos desde{' '}
                            <Link href={route('master.messages.index')} className="font-semibold text-primary underline-offset-2 hover:underline">
                                Mensajes no entregados
                            </Link>
                            , o vuelve a lanzar la facturación desde tu Integra.
                        </p>
                    </Paso>
                </div>

                <p className="text-xs leading-relaxed text-muted-foreground">
                    ¿Te atascaste? Escríbenos y lo hacemos contigo por videollamada. Lo que no podemos hacer es
                    poner la tarjeta por ti: Meta sólo deja hacerlo al dueño de la cuenta.
                </p>
            </div>
        </>
    );
}

GuiaPagoMeta.layout = page => <AppLayout>{page}</AppLayout>;

const ETIQUETA = {
    pago_pendiente: 'Pago pendiente',
    pago_restringido: 'Pago restringido',
    sin_moneda: 'Sin moneda',
    sin_metodo_de_pago: 'Sin tarjeta',
};

/** El código de la moneda, dicho en palabras: «$ 167,89» a secas se leyó como dólares. */
const MONEDA = {
    COP: 'pesos colombianos',
    USD: 'dólares estadounidenses',
    MXN: 'pesos mexicanos',
    EUR: 'euros',
};

const CATEGORIA = {
    MARKETING: 'Marketing',
    UTILITY: 'Utilidad',
    AUTHENTICATION: 'Autenticación',
    AUTHENTICATION_INTERNATIONAL: 'Autenticación internacional',
    SERVICE: 'Servicio',
};

/**
 * El motivo real, con el texto de Meta tal cual debajo.
 *
 * Hasta el 3-oct-2026 todo salía como «no tienes un método de pago», y una
 * empresa con la tarjeta asociada y un cobro rechazado por saldo contestó que
 * su tarjeta no estaba desasociada: tenía razón, y el aviso perdió crédito. Las
 * palabras de Meta, en inglés y sin traducir, son la prueba de que no lo
 * decimos nosotros.
 */
function EstadoDeLaLinea({ linea }) {
    const que = linea.explicacion ?? {};

    return (
        <Aviso tono="alto" titulo={que.titulo ?? 'Tus mensajes no están saliendo'}>
            <p>
                Meta está rechazando los envíos de <strong>{linea.nombre}{linea.numero ? ` (${linea.numero})` : ''}</strong>.{' '}
                {que.explicacion}
            </p>
            <p><strong>Qué hacer:</strong> {que.accion}</p>
            {linea.detalle && (
                <figure className="rounded-lg border border-destructive/20 bg-background/60 px-3 py-2.5">
                    <figcaption className="mb-1 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                        <Quote className="size-3" /> Lo que dice Meta
                    </figcaption>
                    <blockquote className="text-[13px] italic leading-relaxed text-foreground">{linea.detalle}</blockquote>
                </figure>
            )}
            <a href={linea.enlace} target="_blank" rel="noopener noreferrer" className="w-fit">
                <Button className="gap-2 bg-[#1877f2] text-white hover:bg-[#166fe0]">
                    <ExternalLink className="size-4" />
                    {linea.pagar_ahora ? 'Pagar el saldo pendiente en Meta' : 'Abrir la facturación en Meta'}
                </Button>
            </a>
        </Aviso>
    );
}

/**
 * Lo que Meta lleva cobrado y a qué portafolio, pedido una vez para la tarjeta
 * de consumo y el paso 2.
 */
function useConsumo(lineaId) {
    const [datos, setDatos] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        setDatos(null);
        setError(null);
        if (!lineaId) return;
        let vivo = true;
        axios.get(route('instances.consumo-meta', lineaId))
            .then(({ data }) => vivo && setDatos(data))
            .catch(() => vivo && setError('Meta no devolvió el consumo de esta cuenta ahora mismo.'));
        return () => { vivo = false; };
    }, [lineaId]);

    return { datos, error };
}

/**
 * Lo que Meta lleva cobrado: este mes y el anterior, por categoría.
 *
 * Es lo más parecido a «cuánto debo» que da la API. El saldo exacto pendiente
 * —con impuestos y descontando lo ya pagado— sólo está en el panel de Meta, y
 * así se dice: un número que no cuadra con la factura de Meta es peor que
 * ninguno.
 *
 * Arriba, el portafolio de Meta que paga: hay líneas en el de Integra y otras
 * en el que creó el propio cliente, y quien tiene varios en su Facebook no
 * sabía en cuál buscar el cobro (6-oct-2026).
 */
function Consumo({ linea, datos, error }) {
    const moneda = datos?.moneda;

    const dinero = (valor) => new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: moneda || 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(valor ?? 0);

    const actual = datos?.periodos?.[0];
    const marketingManda = actual?.categorias?.[0]?.categoria === 'MARKETING' && actual.total > 0;

    return (
        <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <p className="flex items-center gap-2 text-[13px] font-bold text-foreground">
                    <BarChart3 className="size-4 text-accent-foreground" /> Consumo en Meta de {linea.nombre}
                </p>
                {moneda && (
                    <span className="text-[11px] font-semibold text-muted-foreground">
                        Valores en {MONEDA[moneda] ?? moneda} ({moneda})
                    </span>
                )}
            </div>

            {datos?.portafolio && (
                <div className="flex items-start gap-2.5 rounded-lg border bg-background/60 px-3 py-2.5 text-[12.5px] leading-snug">
                    <Building2 className="mt-0.5 size-4 shrink-0 text-accent-foreground" />
                    <div className="min-w-0">
                        <p className="text-foreground">
                            Meta le cobra al portafolio{' '}
                            <strong>{datos.portafolio.nombre ?? 'sin nombre'}</strong>{' '}
                            <span className="tabular-nums text-muted-foreground">· ID {datos.portafolio.id}</span>
                        </p>
                        <p className="text-muted-foreground">
                            {datos.cuenta && <>Cuenta de WhatsApp «{datos.cuenta}». </>}
                            Si en tu Facebook tienes varios portafolios, la tarjeta y la factura están en este.
                        </p>
                    </div>
                </div>
            )}

            {!datos && !error && (
                <p className="flex items-center gap-2 text-[13px] text-muted-foreground">
                    <Loader2 className="size-4 animate-spin" /> Consultando a Meta…
                </p>
            )}
            {(error || (datos && !datos.periodos)) && (
                <p className="text-[13px] text-muted-foreground">{error ?? 'Meta no devolvió el consumo de esta cuenta ahora mismo.'}</p>
            )}

            {datos?.periodos && (
                <div className="grid gap-3 sm:grid-cols-2">
                    {datos.periodos.map(p => (
                        <div key={p.desde} className="rounded-lg border bg-background/60 p-3">
                            <p className="text-[12px] font-semibold text-muted-foreground">
                                {p.periodo}{p.en_curso ? ' · hasta hoy' : ''}
                            </p>
                            <p className="mt-1 text-2xl font-bold tabular-nums text-foreground">
                                {dinero(p.total)}
                                {moneda && <span className="ml-1.5 text-[12px] font-semibold text-muted-foreground">{moneda}</span>}
                            </p>
                            <p className="text-[12px] text-muted-foreground">
                                {p.cobrados.toLocaleString('es-CO')} mensajes cobrados · {p.gratis.toLocaleString('es-CO')} gratis
                            </p>
                            {p.categorias.length > 0 && (
                                <table className="mt-2 w-full text-[12.5px]">
                                    <tbody>
                                        {p.categorias.map(c => (
                                            <tr key={c.categoria} className="border-t first:border-t-0">
                                                <td className="py-1 text-foreground">{CATEGORIA[c.categoria] ?? c.categoria}</td>
                                                <td className="py-1 text-right tabular-nums text-muted-foreground">{c.mensajes.toLocaleString('es-CO')}</td>
                                                <td className="py-1 pl-3 text-right font-semibold tabular-nums text-foreground">{dinero(c.costo)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {marketingManda && (
                <p className="rounded-lg bg-warning/10 px-3 py-2 text-[12.5px] leading-snug text-foreground">
                    La mayor parte del gasto es <strong>Marketing</strong>. Si tus facturas o tirillas salen con una plantilla
                    de Marketing, pásalas a una de <strong>Utilidad</strong>: cuesta unas 15 veces menos.
                </p>
            )}

            <p className="text-[11.5px] leading-snug text-muted-foreground">
                {moneda && <>Las cifras están en {MONEDA[moneda] ?? moneda}, la moneda en que Meta le cobra a esta cuenta. </>}
                Es lo que Meta registra como consumido; puede tardar unas horas en actualizarse. El saldo exacto
                pendiente, con impuestos y descontando lo que ya pagaste, está en la facturación de Meta.
            </p>
        </section>
    );
}

function Dato({ icono: Icono, titulo, children }) {
    return (
        <div className="rounded-xl border bg-card p-4">
            <p className="flex items-center gap-2 text-[13px] font-bold text-foreground">
                <Icono className="size-4 text-accent-foreground" /> {titulo}
            </p>
            <p className="mt-1.5 text-[13px] leading-snug text-muted-foreground">{children}</p>
        </div>
    );
}

/**
 * «Ya lo hice, comprobar». Pregunta a Meta y dice el resultado en palabras.
 *
 * Tras un sí se recarga la página entera: la alerta roja del layout viene en
 * las props compartidas y tiene que desaparecer en el acto, no en la siguiente
 * navegación.
 */
function Comprobar({ linea }) {
    const [estado, setEstado] = useState(null);
    const [cargando, setCargando] = useState(false);

    async function comprobar() {
        setCargando(true);
        setEstado(null);
        try {
            const { data } = await axios.post(route('instances.comprobar-pago', linea.id));
            setEstado(data);
            // Se recarga para que desaparezca la alerta roja, salvo si hay un
            // aviso de límite que leer: recargar se lo llevaría por delante.
            if (data.ok && !data.limitada) {
                setTimeout(() => window.location.reload(), 2500);
            }
        } catch (e) {
            setEstado({
                ok: false,
                mensaje: e?.response?.status === 429
                    ? 'Has comprobado muchas veces seguidas. Espera un minuto.'
                    : 'No pudimos preguntarle a Meta. Inténtalo en un momento.',
            });
        } finally {
            setCargando(false);
        }
    }

    return (
        <div className="flex flex-col gap-3">
            <Button onClick={comprobar} disabled={cargando} variant="outline" className="w-fit gap-2">
                {cargando ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                {cargando ? 'Preguntando a Meta…' : `Ya lo hice, comprobar ${linea.nombre}`}
            </Button>
            {/* Tres tonos y no dos: «pagado pero con tope» en rojo se leyó
                como que la tarjeta seguía mal (JHeda, 30-sep-2026). */}
            {estado && (
                <div className={`flex items-start gap-2 rounded-lg border px-3 py-2.5 text-[13px] leading-relaxed ${
                    estado.ok && estado.limitada
                        ? 'border-warning/40 bg-warning/10 text-foreground'
                        : estado.ok
                            ? 'border-success/30 bg-success/10 text-success'
                            : 'border-destructive/30 bg-destructive/10 text-destructive'
                }`}>
                    {estado.ok
                        ? <CircleCheck className={`mt-0.5 size-4 shrink-0 ${estado.limitada ? 'text-success' : ''}`} />
                        : <CircleAlert className="mt-0.5 size-4 shrink-0" />}
                    <span>
                        {estado.mensaje}
                        {estado.guia && (
                            <>
                                {' '}
                                <Link href={estado.guia} className="font-semibold text-primary underline-offset-2 hover:underline">
                                    Ver cómo verificar el negocio y subir el límite
                                </Link>
                            </>
                        )}
                    </span>
                </div>
            )}
        </div>
    );
}
