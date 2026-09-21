/**
 * La ficha del cliente en Integra, dentro del panel del chat.
 *
 * El agente veía el número y el nombre; para saber si quien escribe debe plata,
 * por qué tiene el servicio caído o si ya reportó la falla ayer tenía que
 * salirse a Integra y buscarlo a mano, con el cliente esperando. Aquí está lo
 * que el ERP sabe de esa persona: contratos y estado del servicio, facturas
 * pendientes e historial, últimos pagos y radicados.
 *
 * El panel es el resumen; **el detalle se abre en un diálogo**, porque en una
 * columna de 380 px no cabe una factura con sus ítems ni un contrato con su
 * ciclo, su WiFi y su consumo. Lo que el diálogo puede mostrar lo decide el API
 * de Integra, y no es lo mismo para cada bloque:
 *
 *   - **Facturas**: hay detalle de verdad (`GET /facturas/{id}`) con los ítems
 *     cobrados línea por línea. Es lo que responde "¿y esos $76.000 de qué son?".
 *   - **Contratos**: el resumen que ya se descargó trae mucho más de lo que cabe
 *     en el panel (ciclo de facturación, condiciones, WiFi, contrato firmado,
 *     consumo por día), así que el diálogo no pide nada: sólo lo despliega.
 *   - **Pagos y radicados**: Integra NO expone detalle de ninguno de los dos
 *     (no hay `GET /pagos/{id}` ni `GET /radicados/{id}`), así que el diálogo
 *     lista lo que ya llegó y lo dice. Inventar un "ver más" que no puede
 *     mostrar nada nuevo sería peor que no tenerlo.
 *
 * Todo lo pinta un solo endpoint (`/api/integrations/integra/ficha`), que es
 * quien habla con Integra y quien decide por qué criterio se busca al cliente.
 * Aquí no se arma ninguna consulta ni se normaliza ningún teléfono.
 */
import { useEffect, useState } from 'react';
import axios from 'axios';
import { clsx } from 'clsx';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    Check,
    ChevronDown,
    ChevronRight,
    Copy,
    FileText,
    Loader2,
    MapPin,
    Receipt,
    RefreshCw,
    Wallet,
    Wifi,
    Wrench,
    X,
} from 'lucide-react';

function formatCOP(value) {
    if (value == null || isNaN(Number(value))) return null;
    return '$' + Number(value).toLocaleString('es-CO');
}

/** "2026-09-01" → "1 sep 2026". Las fechas de Integra vienen ya en Y-m-d. */
function formatFecha(value) {
    if (!value) return null;
    const d = new Date(String(value).length <= 10 ? `${value}T00:00:00` : value);
    if (isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' });
}

const ESTADO_RADICADO = {
    pendiente:  { label: 'Pendiente',  cls: 'bg-warning/15 text-warning' },
    en_proceso: { label: 'En proceso', cls: 'bg-sky-500/15 text-sky-600 dark:text-sky-400' },
    escalado:   { label: 'Escalado',   cls: 'bg-destructive/15 text-destructive' },
    solventado: { label: 'Solventado', cls: 'bg-success/15 text-success' },
};

const ESTADO_FACTURA = {
    abierta: { label: 'Abierta', cls: 'bg-warning/15 text-warning' },
    cerrada: { label: 'Pagada',  cls: 'bg-success/15 text-success' },
    anulada: { label: 'Anulada', cls: 'bg-black/5 dark:bg-white/5 text-muted-foreground' },
};

/** Cabecera de bloque que se pliega. El contenido pesado nace cerrado. */
function Bloque({ titulo, icono: Icono, contador, abierto, onToggle, children }) {
    return (
        <div className="space-y-2">
            <button onClick={onToggle} className="flex w-full items-center gap-2 text-left">
                <Icono className="size-3.5 text-muted-foreground shrink-0" />
                <span className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60">{titulo}</span>
                {contador != null && (
                    <span className="text-[10px] font-bold text-muted-foreground/60">({contador})</span>
                )}
                <ChevronDown className={clsx('size-3.5 text-muted-foreground/60 ml-auto transition-transform', abierto && 'rotate-180')} />
            </button>
            {abierto && children}
        </div>
    );
}

/**
 * Cifra suelta con su etiqueta. Si trae `onClick` se comporta como botón: es el
 * gesto natural para "¿cuáles?" cuando lo que se ve es un número.
 */
function Dato({ label, valor, tono = 'normal', onClick }) {
    const Etiqueta = onClick ? 'button' : 'div';

    return (
        <Etiqueta
            onClick={onClick}
            className={clsx(
                'rounded-lg bg-black/5 dark:bg-white/5 px-3 py-2 text-left w-full',
                onClick && 'hover:bg-black/10 dark:hover:bg-white/10 transition-colors',
            )}
        >
            <p className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground/60 flex items-center gap-1">
                {label}
                {onClick && <ChevronRight className="size-2.5" />}
            </p>
            <p className={clsx(
                'text-sm font-bold tabular-nums',
                tono === 'deuda' && 'text-destructive',
                tono === 'favor' && 'text-success',
                tono === 'normal' && 'text-foreground',
            )}>{valor}</p>
        </Etiqueta>
    );
}

function Contrato({ contrato, onAbrir }) {
    const activo = contrato.activo;

    return (
        <button
            onClick={onAbrir}
            className="w-full text-left rounded-xl border border-border/50 px-3 py-2.5 space-y-1.5 hover:border-border hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors"
        >
            <div className="flex items-center gap-2">
                <span className="font-mono text-xs font-bold text-foreground">#{contrato.nro}</span>
                <span className={clsx(
                    'text-[10px] font-bold px-2 py-0.5 rounded-full',
                    activo ? 'bg-success/15 text-success' : 'bg-destructive/15 text-destructive',
                )}>
                    {activo ? 'Activo' : 'Suspendido'}
                </span>
                {contrato.television && (
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-black/5 dark:bg-white/5 text-muted-foreground">TV</span>
                )}
                <ChevronRight className="size-3.5 text-muted-foreground/60 ml-auto" />
            </div>

            {contrato.plan && (
                <p className="text-xs text-foreground">
                    {contrato.plan}
                    {contrato.bajada ? <span className="text-muted-foreground"> · {contrato.bajada}/{contrato.subida} Mbps</span> : null}
                    {contrato.precio ? <span className="text-muted-foreground"> · {formatCOP(contrato.precio)}</span> : null}
                </p>
            )}

            {/* El porqué del estado. Sin él, "suspendido" no distingue entre
                «pague y se reactiva» y «hay una avería», que es justo lo que
                define la respuesta que el agente va a escribir. */}
            {!activo && contrato.detalle && (
                <p className="text-[11px] text-muted-foreground">
                    {contrato.detalle}
                    {contrato.monto_para_reactivar
                        ? <> Reactiva con <span className="font-semibold text-foreground">{formatCOP(contrato.monto_para_reactivar)}</span>.</>
                        : null}
                </p>
            )}

            {contrato.promesa_pago && (
                <p className={clsx('text-[11px]', contrato.promesa_pago.vigente ? 'text-warning' : 'text-muted-foreground')}>
                    Promesa de pago hasta {formatFecha(contrato.promesa_pago.vence)}
                    {contrato.promesa_pago.vigente ? ' (vigente)' : ' (vencida)'}
                </p>
            )}

            {contrato.direccion && (
                <p className="text-[11px] text-muted-foreground flex items-start gap-1.5">
                    <MapPin className="size-3 shrink-0 mt-0.5" />
                    {contrato.direccion}
                </p>
            )}
        </button>
    );
}

/** Fila de una lista del diálogo. Clicable sólo si hay algo más que ver. */
function Fila({ titulo, subtitulo, derecha, onClick }) {
    const Etiqueta = onClick ? 'button' : 'div';

    return (
        <Etiqueta
            onClick={onClick}
            className={clsx(
                'w-full text-left flex items-center justify-between gap-3 rounded-lg px-3 py-2.5',
                onClick ? 'hover:bg-black/5 dark:hover:bg-white/5 transition-colors' : '',
            )}
        >
            <div className="min-w-0">
                <p className="text-sm text-foreground truncate">{titulo}</p>
                {subtitulo && <p className="text-[11.5px] text-muted-foreground">{subtitulo}</p>}
            </div>
            <div className="shrink-0 flex items-center gap-1.5">
                {derecha}
                {onClick && <ChevronRight className="size-3.5 text-muted-foreground/60" />}
            </div>
        </Etiqueta>
    );
}

/** Pareja etiqueta/valor de las fichas del diálogo. */
function Campo({ label, valor, mono = false }) {
    if (valor == null || valor === '') return null;

    return (
        <div className="flex items-baseline justify-between gap-3 py-1.5 border-b border-border/30 last:border-0">
            <span className="text-[11.5px] text-muted-foreground shrink-0">{label}</span>
            <span className={clsx(
                'text-foreground text-right',
                // Monoespaciada la que se lee dígito a dígito para teclearla en
                // otra parte: en proporcional, un 1 y una l de una IP se
                // confunden justo cuando el técnico la está copiando.
                mono ? 'text-[12px] font-mono' : 'text-[12.5px]',
            )}>{valor}</span>
        </div>
    );
}

/** El detalle de una factura: se pide al abrirla porque el listado va sin ítems. */
function DetalleFactura({ facturaId }) {
    const [cargando, setCargando] = useState(true);
    const [error, setError] = useState(null);
    const [detalle, setDetalle] = useState(null);

    useEffect(() => {
        let vivo = true;
        setCargando(true);
        setError(null);

        axios.get(`/api/integrations/integra/factura/${facturaId}`)
            .then(({ data }) => { if (vivo) setDetalle(data); })
            .catch(err => { if (vivo) setError(err?.response?.data?.message ?? 'No se pudo abrir la factura en Integra.'); })
            .finally(() => { if (vivo) setCargando(false); });

        return () => { vivo = false; };
    }, [facturaId]);

    if (cargando) {
        return (
            <p className="flex items-center gap-2 text-sm text-muted-foreground py-6">
                <Loader2 className="size-4 animate-spin" /> Abriendo la factura…
            </p>
        );
    }

    if (error) return <p className="text-sm text-muted-foreground py-6">{error}</p>;

    const f = detalle?.factura ?? {};
    const estado = ESTADO_FACTURA[f.estado] ?? { label: f.estado, cls: 'bg-black/5 dark:bg-white/5 text-muted-foreground' };
    const items = f.items ?? [];

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-2">
                <span className="font-mono text-sm font-bold text-foreground">{f.codigo}</span>
                <span className={clsx('text-[10px] font-bold px-2 py-0.5 rounded-full', estado.cls)}>{estado.label}</span>
                {f.tipo_label === 'electronica' && (
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-sky-500/15 text-sky-600 dark:text-sky-400">Electrónica</span>
                )}
            </div>

            <div>
                <Campo label="Fecha" valor={formatFecha(f.fecha)} />
                <Campo label="Vence" valor={formatFecha(f.vencimiento)} />
                <Campo label="Subtotal" valor={formatCOP(f.montos?.subtotal)} />
                {f.montos?.descuento > 0 && <Campo label="Descuento" valor={formatCOP(f.montos.descuento)} />}
                <Campo label="Total" valor={formatCOP(f.montos?.total)} />
                <Campo label="Pagado" valor={formatCOP(f.montos?.pagado)} />
                <Campo label="Por pagar" valor={formatCOP(f.montos?.por_pagar)} />
                {f.observaciones && <Campo label="Observaciones" valor={f.observaciones} />}
            </div>

            {/* Los ítems son el motivo de existir de este diálogo: es el "de qué
                son" que sigue a cualquier cifra que se le diga al cliente. */}
            {items.length > 0 && (
                <div className="space-y-1.5">
                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60">Se le cobró</p>
                    {items.map((it, i) => (
                        <div key={i} className="flex items-baseline justify-between gap-3 text-[12.5px]">
                            <span className="text-foreground min-w-0">
                                {it.producto}
                                {it.cantidad > 1 ? <span className="text-muted-foreground"> ×{it.cantidad}</span> : null}
                                {it.descuento_pct > 0 ? <span className="text-muted-foreground"> −{it.descuento_pct}%</span> : null}
                            </span>
                            <span className="tabular-nums text-foreground shrink-0">{formatCOP(it.subtotal)}</span>
                        </div>
                    ))}
                </div>
            )}

            {f.contratos?.length > 0 && (
                <div className="space-y-1.5">
                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60">Contratos que cubre</p>
                    {f.contratos.map(c => (
                        <p key={c.id} className="text-[12.5px] text-foreground">
                            #{c.nro}
                            {c.plan ? <span className="text-muted-foreground"> · {c.plan}</span> : null}
                            <span className="text-muted-foreground"> · internet {c.internet}</span>
                        </p>
                    ))}
                </div>
            )}
        </div>
    );
}

/** El contrato entero: lo que no cabe en el panel pero ya está descargado. */
/**
 * El diagnóstico de red del contrato (extensión «Diagnóstico de internet»).
 *
 * Es la única parte de la ficha que no se carga con el panel: se pide cuando
 * alguien la pide. Integra se conecta al router del cliente en el momento —2 a
 * 6 segundos, hasta unos 20 en el peor caso—, así que traerlo de oficio
 * significaría esperar eso cada vez que un asesor abre un contrato para mirar
 * el plan, y gastar el cupo de 20 consultas por minuto de toda la empresa en
 * gente que no preguntó nada.
 *
 * Tampoco se guarda: un diagnóstico de hace un minuto ya no dice nada de «no me
 * sirve el internet», que es la conversación en la que esto se usa.
 *
 * Los 19 códigos de veredicto viven en el Swagger de Integra y aquí se pintan
 * como vienen, sólo con el guion bajo quitado. Es a propósito: media docena de
 * etiquetas escritas a mano y trece códigos crudos se lee peor que trece
 * códigos legibles, y a la primera que Integra añadiera un veredicto nuevo
 * tendríamos un hueco. Lo que el asesor lee de verdad es el informe redactado.
 */
function DiagnosticoDeRed({ conversationId, contratoNro, conInforme }) {
    const [estado, setEstado] = useState('inicial');   // inicial | cargando | listo | error
    const [resultado, setResultado] = useState(null);
    const [error, setError] = useState(null);
    const [copiado, setCopiado] = useState(false);

    async function diagnosticar() {
        setEstado('cargando');
        setError(null);
        try {
            const { data } = await axios.get('/api/integrations/integra/diagnostico', {
                params: { conversation_id: conversationId, contrato: contratoNro },
                // Más que el del servidor a Integra (35 s), para que quien
                // corte sea siempre el que tiene el motivo que contar.
                timeout: 45000,
            });
            setResultado(data.diagnostico ?? null);
            setEstado('listo');
        } catch (err) {
            setError(
                err?.code === 'ECONNABORTED'
                    ? 'Integra tardó demasiado en contestar. Vuelve a intentarlo.'
                    : err?.response?.data?.message ?? 'No se pudo diagnosticar la red.'
            );
            setEstado('error');
        }
    }

    const veredicto = resultado?.veredicto ?? {};
    const visita = veredicto.visita;
    const tono = visita === true ? 'warning' : visita === false ? 'success' : 'info';

    async function copiar() {
        try {
            await navigator.clipboard.writeText(resultado.whatsapp);
            setCopiado(true);
            setTimeout(() => setCopiado(false), 2000);
        } catch {
            // Sin permiso de portapapeles no hay nada que avisar: el texto está
            // a la vista y se puede seleccionar a mano.
        }
    }

    return (
        <div className="space-y-2">
            <button
                type="button"
                onClick={diagnosticar}
                disabled={estado === 'cargando'}
                className="inline-flex w-full items-center justify-center gap-1.5 rounded-xl border border-primary/40 bg-primary/10 px-3 py-2 text-[12.5px] font-bold text-accent-foreground transition-colors hover:bg-primary/20 disabled:opacity-60"
            >
                {estado === 'cargando'
                    ? <><Loader2 className="size-3.5 animate-spin" /> Consultando el equipo…</>
                    : <><Activity className="size-3.5" /> {estado === 'inicial' ? 'Diagnosticar la red' : 'Volver a diagnosticar'}</>}
            </button>

            {/* La espera se explica mientras dura. Sin esto, veinte segundos de
                botón girando se leen como que la pantalla se colgó. */}
            {estado === 'cargando' && (
                <p className="text-[11px] leading-relaxed text-muted-foreground">
                    Integra se está conectando al router del cliente. Suele tardar unos segundos y
                    puede llegar a veinte si el equipo está en las últimas.
                </p>
            )}

            {estado === 'error' && (
                <p className="flex items-start gap-1.5 rounded-lg border border-destructive/30 bg-destructive/10 px-2.5 py-2 text-[11.5px] leading-relaxed text-destructive">
                    <AlertTriangle className="mt-px size-3.5 shrink-0" />
                    {error}
                </p>
            )}

            {estado === 'listo' && resultado && (
                <div className={clsx(
                    'rounded-xl border p-3',
                    tono === 'warning' && 'border-warning/40 bg-warning/10',
                    tono === 'success' && 'border-success/30 bg-success/10',
                    tono === 'info' && 'border-border bg-muted/40'
                )}>
                    <div className="flex items-center gap-1.5">
                        <span className={clsx(
                            'size-1.5 shrink-0 rounded-full',
                            tono === 'warning' ? 'bg-warning' : tono === 'success' ? 'bg-success' : 'bg-muted-foreground'
                        )} />
                        <span className="text-[12.5px] font-bold text-foreground">
                            {legible(veredicto.codigo) || 'Sin veredicto'}
                        </span>
                    </div>

                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {/* `null` en «visita» no es «no»: es que depende de lo
                            que haga el cliente, y decirlo importa tanto como
                            los otros dos casos. */}
                        <Insignia tono={tono}>
                            {visita === true
                                ? <><Wrench className="size-2.5" /> Requiere visita</>
                                : visita === false
                                    ? <><Check className="size-2.5" /> Sin visita</>
                                    : 'La visita depende del cliente'}
                        </Insignia>

                        {veredicto.responsable && (
                            <Insignia>{legible(veredicto.responsable)}</Insignia>
                        )}
                    </div>

                    {/* Confianza media: Integra no está seguro. Mandar una
                        cuadrilla con esto es mandarla a medias. */}
                    {veredicto.confianza === 'media' && (
                        <p className="mt-2 flex items-start gap-1.5 text-[11px] leading-relaxed text-warning">
                            <AlertTriangle className="mt-px size-3 shrink-0" />
                            Integra no está seguro del todo. Repite el diagnóstico antes de despachar
                            a un técnico.
                        </p>
                    )}

                    {conInforme && resultado.whatsapp && (
                        <div className="mt-2.5 rounded-lg border border-border/60 bg-background/60 p-2.5">
                            <div className="flex items-center gap-2">
                                <span className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/70">
                                    Informe para el cliente
                                </span>
                                <button
                                    type="button"
                                    onClick={copiar}
                                    className="ml-auto inline-flex items-center gap-1 text-[10.5px] font-bold text-accent-foreground hover:underline"
                                >
                                    {copiado ? <><Check className="size-2.5" /> Copiado</> : <><Copy className="size-2.5" /> Copiar</>}
                                </button>
                            </div>
                            <p className="mt-1.5 whitespace-pre-wrap text-[11.5px] leading-relaxed text-foreground">
                                {resultado.whatsapp}
                            </p>
                            {/* Que no se envía solo se dice aquí, donde está el
                                botón de copiar, y no en la ficha de la
                                extensión que nadie relee. */}
                            <p className="mt-1.5 text-[10.5px] text-muted-foreground">
                                No se le ha enviado nada al cliente: esto se copia y se pega.
                            </p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/** `nodo_incomunicado` → «Nodo incomunicado». */
function legible(codigo) {
    if (!codigo) return null;

    const texto = String(codigo).replace(/_/g, ' ');

    return texto.charAt(0).toUpperCase() + texto.slice(1);
}

/** Insignia del diagnóstico. */
function Insignia({ tono = 'muted', children }) {
    return (
        <span className={clsx(
            'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-bold',
            tono === 'warning' && 'border-warning/40 bg-warning/15 text-warning',
            tono === 'success' && 'border-success/30 bg-success/15 text-success',
            (tono === 'muted' || tono === 'info') && 'border-border bg-muted text-muted-foreground'
        )}>
            {children}
        </span>
    );
}

function DetalleContrato({ contrato, onFactura, diagnostico, conversationId }) {
    const a = contrato.ampliado ?? {};
    const consumoPorDia = a.consumo?.por_dia ?? [];

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-2">
                <span className="font-mono text-sm font-bold text-foreground">#{contrato.nro}</span>
                <span className={clsx(
                    'text-[10px] font-bold px-2 py-0.5 rounded-full',
                    contrato.activo ? 'bg-success/15 text-success' : 'bg-destructive/15 text-destructive',
                )}>
                    {contrato.activo ? 'Activo' : 'Suspendido'}
                </span>
            </div>

            {contrato.detalle && <p className="text-[12.5px] text-muted-foreground">{contrato.detalle}</p>}

            {/* Arriba del todo, y no al final entre el WiFi y las facturas: se
                abre este contrato justo porque el cliente dice que no tiene
                internet, así que lo primero que se busca aquí es esto. */}
            {diagnostico?.activa && conversationId && contrato.nro && (
                <DiagnosticoDeRed
                    conversationId={conversationId}
                    contratoNro={contrato.nro}
                    conInforme={diagnostico.informe !== false}
                />
            )}

            <div>
                <Campo label="Plan" valor={contrato.plan} />
                <Campo label="Velocidad" valor={contrato.bajada ? `${contrato.bajada}/${contrato.subida} Mbps` : null} />
                <Campo label="Precio" valor={formatCOP(contrato.precio)} />
                <Campo label="Tecnología" valor={contrato.tecnologia} />
                <Campo label="IP" valor={contrato.ip} mono />
                <Campo label="Televisión" valor={contrato.television ? 'Sí' : null} />
                <Campo label="Dirección" valor={contrato.direccion} />
                <Campo label="Monto para reactivar" valor={formatCOP(contrato.monto_para_reactivar)} />
            </div>

            {contrato.ciclo && (
                <div>
                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60 mb-1">Ciclo de facturación</p>
                    <Campo label="Grupo" valor={contrato.ciclo.grupo} />
                    <Campo label="Factura el día" valor={contrato.ciclo.dia_factura} />
                    <Campo label="Paga el día" valor={contrato.ciclo.dia_pago} />
                    <Campo label="Corte el día" valor={contrato.ciclo.dia_corte} />
                </div>
            )}

            {a.condiciones && (
                <div>
                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60 mb-1">Condiciones</p>
                    <Campo label="Permanencia" valor={a.condiciones.permanencia_meses ? `${a.condiciones.permanencia_meses} meses` : 'Sin cláusula'} />
                    <Campo label="Costo de reconexión" valor={a.condiciones.costo_reconexion > 0 ? formatCOP(a.condiciones.costo_reconexion) : null} />
                    <Campo label="Descuento" valor={a.condiciones.descuento > 0 ? formatCOP(a.condiciones.descuento) : null} />
                    <Campo label="Descuento hasta" valor={formatFecha(a.condiciones.descuento_hasta)} />
                </div>
            )}

            {a.wifi && (
                <div className="rounded-xl border border-border/50 px-3 py-2.5">
                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60 flex items-center gap-1.5 mb-1">
                        <Wifi className="size-3" /> WiFi registrado
                    </p>
                    <Campo label="Red" valor={a.wifi.red} />
                    <Campo label="Clave" valor={a.wifi.clave} />
                </div>
            )}

            {a.consumo?.mes_actual && (
                <div>
                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60 mb-1">Consumo del mes</p>
                    <Campo label="Total" valor={`${a.consumo.mes_actual.total_gb} GB`} />
                    <Campo label="Descarga" valor={`${a.consumo.mes_actual.descarga_gb} GB`} />
                    <Campo label="Subida" valor={`${a.consumo.mes_actual.subida_gb} GB`} />
                    {consumoPorDia.length > 0 && (
                        <div className="pt-2 space-y-1">
                            {consumoPorDia.map(d => (
                                <div key={d.dia} className="flex items-baseline justify-between text-[11.5px]">
                                    <span className="text-muted-foreground">{formatFecha(d.dia)}</span>
                                    <span className="text-foreground tabular-nums">{d.descarga_gb} GB</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {a.contrato_digital && (
                <div>
                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60 mb-1">Contrato firmado</p>
                    <Campo label="Estado" valor={a.contrato_digital.firmado ? 'Firmado' : 'Sin firmar'} />
                    <Campo label="Fecha de firma" valor={formatFecha(a.contrato_digital.fecha_firma)} />
                    {a.contrato_digital.pdf_url && (
                        <a
                            href={a.contrato_digital.pdf_url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 text-[12px] font-bold text-accent-foreground hover:underline pt-1.5"
                        >
                            <FileText className="size-3.5" /> Abrir el PDF
                        </a>
                    )}
                </div>
            )}

            {a.facturas?.length > 0 && (
                <div className="space-y-0.5">
                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60">Facturas pendientes de este contrato</p>
                    {a.facturas.map((f, i) => (
                        <Fila
                            key={f.id ?? i}
                            titulo={f.codigo}
                            subtitulo={`${f.vencida ? 'Venció el ' : 'Vence el '}${formatFecha(f.vencimiento)}`}
                            derecha={<span className="text-sm font-bold tabular-nums text-foreground">{formatCOP(f.por_pagar)}</span>}
                            onClick={f.id ? () => onFactura(f.id) : undefined}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

/**
 * El diálogo. Lleva una pila de vistas para que entrar a una factura desde una
 * lista tenga vuelta atrás: sin ella, cerrar el detalle cerraba también la lista
 * y había que volver a abrirla desde el panel.
 */
function DialogoIntegra({ pila, ficha, onCerrar, onEntrar, onVolver, diagnostico, conversationId }) {
    const vista = pila[pila.length - 1];
    const pendientes = ficha.facturas?.pendientes ?? [];
    const historial = ficha.facturas?.historial ?? [];

    const TITULOS = {
        pendientes: 'Facturas pendientes',
        historial: 'Historial de facturas',
        pagos: 'Últimos pagos',
        radicados: 'Radicados',
        contrato: 'Contrato',
        factura: 'Factura',
    };

    const contrato = vista.tipo === 'contrato'
        ? (ficha.contratos ?? []).find(c => String(c.nro) === String(vista.nro))
        : null;

    return (
        <div
            className="fixed inset-0 z-[120] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-in fade-in duration-200"
            onClick={onCerrar}
        >
            <div
                className="w-full max-w-lg max-h-[85vh] flex flex-col rounded-3xl border border-border/10 bg-white dark:bg-[#1c272e] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200"
                onClick={e => e.stopPropagation()}
            >
                <div className="flex items-center gap-2 px-5 py-4 border-b border-border/40">
                    {pila.length > 1 && (
                        <button onClick={onVolver} title="Volver" className="size-8 -ml-1 flex items-center justify-center rounded-lg text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5">
                            <ArrowLeft className="size-4" />
                        </button>
                    )}
                    <div className="min-w-0">
                        <h3 className="font-bold text-base leading-tight text-foreground">{TITULOS[vista.tipo]}</h3>
                        <p className="text-[11.5px] text-muted-foreground truncate">{ficha.cliente?.nombre} · Integra</p>
                    </div>
                    <button onClick={onCerrar} title="Cerrar" className="size-8 ml-auto flex items-center justify-center rounded-lg text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5">
                        <X className="size-4" />
                    </button>
                </div>

                <div className="overflow-y-auto px-5 py-4">
                    {vista.tipo === 'pendientes' && (
                        <div className="space-y-0.5">
                            {pendientes.length === 0 && <p className="text-sm text-muted-foreground py-4">No tiene facturas pendientes.</p>}
                            {pendientes.map((f, i) => (
                                <Fila
                                    key={f.codigo ?? i}
                                    titulo={f.codigo}
                                    subtitulo={`${f.vencida ? 'Venció el ' : 'Vence el '}${formatFecha(f.vencimiento)}${f.contrato_nro ? ` · contrato #${f.contrato_nro}` : ''}`}
                                    derecha={<span className={clsx('text-sm font-bold tabular-nums', f.vencida ? 'text-destructive' : 'text-foreground')}>{formatCOP(f.por_pagar)}</span>}
                                    // El listado de pendientes viene de /contactos/buscar, que no
                                    // manda el id de la factura: sin id no hay detalle que pedir.
                                    // Las del contrato sí lo traen, y ahí sí se puede entrar.
                                    onClick={f.id ? () => onEntrar({ tipo: 'factura', id: f.id }) : undefined}
                                />
                            ))}
                            {pendientes.some(f => !f.id) && (
                                <p className="text-[11px] text-muted-foreground pt-2">
                                    Para ver los ítems de una factura, ábrela desde su contrato.
                                </p>
                            )}
                        </div>
                    )}

                    {vista.tipo === 'historial' && (
                        <div className="space-y-0.5">
                            {historial.map((f, i) => {
                                const estado = ESTADO_FACTURA[f.estado] ?? { label: f.estado, cls: 'bg-black/5 dark:bg-white/5 text-muted-foreground' };

                                return (
                                    <Fila
                                        key={f.id ?? i}
                                        titulo={f.codigo}
                                        subtitulo={`${formatFecha(f.fecha)}${f.electronica ? ' · electrónica' : ''}`}
                                        derecha={
                                            <div className="text-right">
                                                <p className="text-sm font-semibold tabular-nums text-foreground">{formatCOP(f.total)}</p>
                                                <span className={clsx('text-[10px] font-bold px-2 py-0.5 rounded-full', estado.cls)}>{estado.label}</span>
                                            </div>
                                        }
                                        onClick={f.id ? () => onEntrar({ tipo: 'factura', id: f.id }) : undefined}
                                    />
                                );
                            })}
                        </div>
                    )}

                    {vista.tipo === 'pagos' && (
                        <div className="space-y-0.5">
                            {(ficha.pagos ?? []).map((p, i) => (
                                <Fila
                                    key={p.recibo ?? i}
                                    titulo={`Recibo ${p.recibo}`}
                                    subtitulo={`${formatFecha(p.fecha)}${p.medio ? ` · ${p.medio}` : ''}`}
                                    derecha={<span className="text-sm font-bold tabular-nums text-success">{formatCOP(p.valor)}</span>}
                                />
                            ))}
                            {/* Integra no expone el detalle de un recibo, así que
                                esto es todo lo que hay. Decirlo evita que alguien
                                busque el "ver más" que no existe. */}
                            <p className="text-[11px] text-muted-foreground pt-2">
                                Integra entrega del recibo el número, la fecha, el valor y el medio. El desglose sólo está en el ERP.
                            </p>
                        </div>
                    )}

                    {vista.tipo === 'radicados' && (
                        <div className="space-y-0.5">
                            {(ficha.radicados ?? []).map((r, i) => {
                                const estado = ESTADO_RADICADO[r.estado] ?? { label: r.estado ?? '—', cls: 'bg-black/5 dark:bg-white/5 text-muted-foreground' };

                                return (
                                    <Fila
                                        key={r.codigo ?? i}
                                        titulo={`#${r.codigo} · ${r.servicio ?? 'Soporte'}`}
                                        subtitulo={`${formatFecha(r.fecha)}${r.contrato_nro ? ` · contrato #${r.contrato_nro}` : ''}`}
                                        derecha={<span className={clsx('text-[10px] font-bold px-2 py-0.5 rounded-full', estado.cls)}>{estado.label}</span>}
                                    />
                                );
                            })}
                            <p className="text-[11px] text-muted-foreground pt-2">
                                Integra entrega del radicado el código, la fecha, el servicio y el estado. Las notas del técnico sólo están en el ERP.
                            </p>
                        </div>
                    )}

                    {vista.tipo === 'contrato' && contrato && (
                        <DetalleContrato
                            contrato={contrato}
                            onFactura={(id) => onEntrar({ tipo: 'factura', id })}
                            diagnostico={diagnostico}
                            conversationId={conversationId}
                        />
                    )}

                    {vista.tipo === 'factura' && <DetalleFactura facturaId={vista.id} />}
                </div>
            </div>
        </div>
    );
}

export default function FichaIntegra({ conversationId, diagnostico = { activa: false, informe: true } }) {
    const [cargando, setCargando] = useState(true);
    const [error, setError] = useState(null);
    const [ficha, setFicha] = useState(null);
    const [pila, setPila] = useState([]);   // vistas abiertas en el diálogo
    const [abierto, setAbierto] = useState({
        contratos: true,
        pendientes: true,
        pagos: false,
        radicados: false,
        historial: false,
    });

    const toggle = (clave) => setAbierto(a => ({ ...a, [clave]: !a[clave] }));
    const abrir = (vista) => setPila([vista]);
    const entrar = (vista) => setPila(p => [...p, vista]);
    const volver = () => setPila(p => p.slice(0, -1));

    const cargar = async (refrescar = false) => {
        setCargando(true);
        setError(null);
        try {
            const { data } = await axios.get('/api/integrations/integra/ficha', {
                params: { conversation_id: conversationId, refrescar: refrescar ? 1 : undefined },
            });
            setFicha(data);
        } catch (err) {
            // 422 sin conexión, 404 conversación ajena: el mensaje lo redacta el
            // backend (habla de tokens y scopes, que es lo que hay que leer).
            setError(err?.response?.data?.message ?? 'No se pudo consultar Integra.');
            setFicha(null);
        } finally {
            setCargando(false);
        }
    };

    useEffect(() => {
        setPila([]);
        if (conversationId) cargar();
    }, [conversationId]);

    const cabecera = (
        <div className="flex items-center justify-between">
            <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50">Integra</p>
            <button
                onClick={() => cargar(true)}
                disabled={cargando}
                title="Volver a consultar Integra"
                className="inline-flex items-center gap-1 text-[11px] font-bold text-accent-foreground hover:underline disabled:opacity-50"
            >
                <RefreshCw className={clsx('size-3', cargando && 'animate-spin')} /> Actualizar
            </button>
        </div>
    );

    if (cargando && !ficha) {
        return (
            <div className="space-y-3">
                {cabecera}
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 className="size-4 animate-spin" /> Consultando Integra…
                </div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="space-y-3">
                {cabecera}
                <p className="text-[11.5px] text-muted-foreground">{error}</p>
            </div>
        );
    }

    if (!ficha) return null;

    if (!ficha.buscable) {
        return (
            <div className="space-y-3">
                {cabecera}
                <p className="text-[11.5px] text-muted-foreground">
                    {ficha.message ?? 'Este chat no tiene número ni identificación con la que buscar en Integra.'}
                </p>
            </div>
        );
    }

    if (!ficha.encontrado) {
        return (
            <div className="space-y-3">
                {cabecera}
                <p className="text-[11.5px] text-muted-foreground">
                    No hay ningún cliente en Integra con {ficha.criterio?.tipo === 'identificacion' ? 'la identificación' : 'el número'}{' '}
                    <span className="font-mono text-foreground">{ficha.criterio?.valor}</span>.
                </p>
            </div>
        );
    }

    const { cliente, resumen, contratos, facturas, pagos, radicados } = ficha;
    const pendientes = facturas?.pendientes ?? [];
    const historial = facturas?.historial ?? [];

    return (
        <div className="space-y-4">
            {cabecera}

            {/* Quién es en el ERP: puede no ser el titular del contrato, y por
                eso el nombre va aquí aunque el panel ya muestre uno arriba. */}
            <div className="space-y-1">
                <p className="text-sm font-semibold text-foreground">{cliente.nombre}</p>
                {cliente.identificacion && (
                    <p className="text-[11.5px] text-muted-foreground font-mono">CC/NIT {cliente.identificacion}</p>
                )}
                {(cliente.direccion || cliente.municipio) && (
                    <p className="text-[11.5px] text-muted-foreground flex items-start gap-1.5">
                        <MapPin className="size-3 shrink-0 mt-0.5" />
                        {[cliente.direccion, cliente.barrio, cliente.municipio].filter(Boolean).join(', ')}
                    </p>
                )}
            </div>

            {/* Varias fichas con el mismo teléfono (el hijo que puso su celular
                en el contrato del padre): se avisa para que el agente no cobre
                sobre la equivocada. */}
            {ficha.coincidencias > 1 && (
                <p className="text-[11px] text-warning flex items-start gap-1.5">
                    <AlertTriangle className="size-3.5 shrink-0 mt-0.5" />
                    En Integra hay {ficha.coincidencias} clientes con ese dato. Se muestra el que mejor coincide.
                </p>
            )}

            <div className="grid grid-cols-2 gap-2">
                <Dato
                    label="Por pagar"
                    valor={formatCOP(resumen?.total_por_pagar ?? 0)}
                    tono={(resumen?.total_por_pagar ?? 0) > 0 ? 'deuda' : 'normal'}
                    onClick={pendientes.length ? () => abrir({ tipo: 'pendientes' }) : undefined}
                />
                <Dato
                    label="Facturas pendientes"
                    valor={resumen?.facturas_pendientes ?? pendientes.length}
                    onClick={pendientes.length ? () => abrir({ tipo: 'pendientes' }) : undefined}
                />
                {resumen?.saldo_a_favor > 0 && (
                    <Dato label="Saldo a favor" valor={formatCOP(resumen.saldo_a_favor)} tono="favor" />
                )}
                {resumen?.radicados_abiertos > 0 && (
                    <Dato
                        label="Radicados abiertos"
                        valor={resumen.radicados_abiertos}
                        tono="deuda"
                        onClick={radicados?.length ? () => abrir({ tipo: 'radicados' }) : undefined}
                    />
                )}
            </div>

            {contratos?.length > 0 && (
                <Bloque titulo="Contratos" icono={Wallet} contador={contratos.length} abierto={abierto.contratos} onToggle={() => toggle('contratos')}>
                    <div className="space-y-2">
                        {contratos.map(c => (
                            <Contrato key={c.nro} contrato={c} onAbrir={() => abrir({ tipo: 'contrato', nro: c.nro })} />
                        ))}
                    </div>
                </Bloque>
            )}

            {pendientes.length > 0 && (
                <Bloque titulo="Facturas pendientes" icono={Receipt} contador={pendientes.length} abierto={abierto.pendientes} onToggle={() => toggle('pendientes')}>
                    <div className="space-y-0.5 -mx-3">
                        {pendientes.map((f, i) => (
                            <Fila
                                key={f.codigo ?? i}
                                titulo={<span className="font-mono">{f.codigo}</span>}
                                subtitulo={`${f.vencida ? 'Venció el ' : 'Vence el '}${formatFecha(f.vencimiento)}${f.contrato_nro ? ` · #${f.contrato_nro}` : ''}`}
                                derecha={<span className={clsx('text-sm font-bold tabular-nums', f.vencida ? 'text-destructive' : 'text-foreground')}>{formatCOP(f.por_pagar)}</span>}
                                onClick={() => abrir(f.id ? { tipo: 'factura', id: f.id } : { tipo: 'pendientes' })}
                            />
                        ))}
                    </div>
                </Bloque>
            )}

            {pagos?.length > 0 && (
                <Bloque titulo="Últimos pagos" icono={FileText} contador={pagos.length} abierto={abierto.pagos} onToggle={() => toggle('pagos')}>
                    <div className="space-y-0.5 -mx-3">
                        {pagos.map((p, i) => (
                            <Fila
                                key={p.recibo ?? i}
                                titulo={`Recibo ${p.recibo}`}
                                subtitulo={`${formatFecha(p.fecha)}${p.medio ? ` · ${p.medio}` : ''}`}
                                derecha={<span className="text-sm font-bold tabular-nums text-success">{formatCOP(p.valor)}</span>}
                                onClick={() => abrir({ tipo: 'pagos' })}
                            />
                        ))}
                    </div>
                </Bloque>
            )}

            {radicados?.length > 0 && (
                <Bloque titulo="Radicados" icono={Wrench} contador={radicados.length} abierto={abierto.radicados} onToggle={() => toggle('radicados')}>
                    <div className="space-y-0.5 -mx-3">
                        {radicados.map((r, i) => {
                            const estado = ESTADO_RADICADO[r.estado] ?? { label: r.estado ?? '—', cls: 'bg-black/5 dark:bg-white/5 text-muted-foreground' };

                            return (
                                <Fila
                                    key={r.codigo ?? i}
                                    titulo={`#${r.codigo} · ${r.servicio ?? 'Soporte'}`}
                                    subtitulo={`${formatFecha(r.fecha)}${r.contrato_nro ? ` · contrato #${r.contrato_nro}` : ''}`}
                                    derecha={<span className={clsx('text-[10px] font-bold px-2 py-0.5 rounded-full', estado.cls)}>{estado.label}</span>}
                                    onClick={() => abrir({ tipo: 'radicados' })}
                                />
                            );
                        })}
                    </div>
                </Bloque>
            )}

            {historial.length > 0 && (
                <Bloque titulo="Historial de facturas" icono={Receipt} contador={historial.length} abierto={abierto.historial} onToggle={() => toggle('historial')}>
                    <button
                        onClick={() => abrir({ tipo: 'historial' })}
                        className="w-full flex items-center justify-between gap-2 rounded-lg bg-black/5 dark:bg-white/5 px-3 py-2.5 hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
                    >
                        <span className="text-[12.5px] text-foreground">Ver las {historial.length} facturas y sus ítems</span>
                        <ChevronRight className="size-3.5 text-muted-foreground" />
                    </button>
                </Bloque>
            )}

            {/* Lo que el token no pudo leer se dice. Callarlo hace que un scope
                que falta parezca un cliente sin pagos ni reportes. */}
            {ficha.sin_permiso?.length > 0 && (
                <p className="text-[11px] text-muted-foreground flex items-start gap-1.5">
                    <AlertTriangle className="size-3.5 shrink-0 mt-0.5" />
                    El token de Integra no pudo leer {ficha.sin_permiso.includes('contratos') ? 'contratos, pagos ni radicados' : 'las facturas'}.
                    Revisa sus permisos en Integraciones.
                </p>
            )}

            {pila.length > 0 && (
                <DialogoIntegra
                    pila={pila}
                    ficha={ficha}
                    onCerrar={() => setPila([])}
                    onEntrar={entrar}
                    onVolver={volver}
                    diagnostico={diagnostico}
                    conversationId={conversationId}
                />
            )}
        </div>
    );
}
