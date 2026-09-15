import { Bell, Clock, Sparkles, UserRound } from 'lucide-react';
import clsx from 'clsx';

/**
 * Maquetas de «Así se ve» para la página de cada extensión.
 *
 * Son un trozo de la interfaz de verdad, no un diagrama de cajas y flechas. La
 * diferencia importa: quien mira esta pantalla está decidiendo si enciende algo
 * en la bandeja donde trabaja su equipo, y lo que necesita saber es qué va a
 * cambiar en su pantalla. Un esquema abstracto explica la mecánica; esto
 * responde la pregunta que de verdad se hace.
 *
 * Viven en el frontend y no en el manifiesto de PHP por lo mismo que los iconos
 * (ver `icons.js`): el catálogo es PHP y no puede mandar JSX. El mapa va por
 * `slug`, y una extensión sin maqueta simplemente no pinta la sección — no se
 * rompe ni deja un hueco.
 *
 * Los datos son inventados a propósito y se nota que lo son ("Pedro G.",
 * "Marta L."): usar nombres que parezcan reales invita a leerlos como si fueran
 * clientes de verdad de la empresa que mira.
 */

// ─── Piezas compartidas ──────────────────────────────────────────────────────

/** El marco con pinta de ventana, para que se lea como «esto es la app». */
function Pantalla({ titulo, children, className }) {
    return (
        <div className={clsx('rounded-lg border bg-background overflow-hidden', className)}>
            <div className="border-b bg-muted/40 px-3 py-1.5 text-[11px] font-semibold text-muted-foreground">
                {titulo}
            </div>
            {children}
        </div>
    );
}

/** Una fila de la lista de chats. */
function Fila({ inicial, nombre, texto, hora, antes, etiqueta, apagada }) {
    return (
        <div className={clsx('flex items-center gap-2.5 px-3 py-2.5', apagada && 'opacity-45')}>
            <div className="size-8 shrink-0 rounded-full bg-muted flex items-center justify-center text-[11px] font-semibold text-muted-foreground">
                {inicial}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5">
                    {antes}
                    <span className="truncate text-[13px] font-semibold text-foreground">{nombre}</span>
                    {etiqueta}
                    <span className="ml-auto shrink-0 text-[11px] text-muted-foreground">{hora}</span>
                </div>
                <p className="truncate text-xs text-muted-foreground">{texto}</p>
            </div>
        </div>
    );
}

function Etiqueta({ children, color }) {
    return (
        <span
            className="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white"
            style={{ backgroundColor: color }}
        >
            {children}
        </span>
    );
}

/** El pie que explica en una línea qué acaba de hacer la extensión. */
function Pie({ children }) {
    return (
        <p className="mt-2 text-xs text-muted-foreground">
            <span className="font-semibold text-foreground">La extensión: </span>
            {children}
        </p>
    );
}

/** Burbuja de WhatsApp saliente, para la firma. */
function Burbuja({ children }) {
    return (
        <div className="flex justify-end px-3 py-2.5">
            <div className="max-w-[85%] rounded-lg rounded-tr-none bg-[#d9fdd3] dark:bg-[#005c4b] px-2.5 py-1.5 text-[13px] leading-snug text-[#111b21] dark:text-white">
                {children}
                <span className="ml-1.5 align-bottom text-[10px] text-[#667781] dark:text-white/60">09:41 ✓✓</span>
            </div>
        </div>
    );
}

// ─── Las caritas del semáforo ────────────────────────────────────────────────
// Mismo dibujo que en la bandeja (`Chat/Index.jsx`). Repetido a conciencia y no
// importado: son dos pantallas que no comparten nada más, y acoplarlas obligaría
// a exportar medio chat para pintar un ejemplo.

const CARITAS = {
    verde: { color: 'text-emerald-500', rasgos: '#064e3b', ojoY: 10.2, boca: 'M7.6 14.4c1.3 2.1 7.5 2.1 8.8 0' },
    amarillo: { color: 'text-amber-500', rasgos: '#451a03', ojoY: 10.2, boca: 'M8.2 15.2h7.6' },
    rojo: {
        color: 'text-red-500', rasgos: '#4c0519', ojoY: 12,
        boca: 'M8.2 16.6c1.2-1.7 6.4-1.7 7.6 0',
        cejas: 'M6.9 8.1 10.6 9.7M17.1 8.1 13.4 9.7',
    },
};

function Carita({ nivel, className = 'size-3.5' }) {
    const c = CARITAS[nivel];

    return (
        <svg viewBox="0 0 24 24" className={clsx('shrink-0', c.color, className)} aria-hidden="true">
            <circle cx="12" cy="12" r="11" fill="currentColor" />
            <g fill={c.rasgos}>
                <circle cx="8.6" cy={c.ojoY} r="1.45" />
                <circle cx="15.4" cy={c.ojoY} r="1.45" />
            </g>
            <g stroke={c.rasgos} strokeWidth="1.7" strokeLinecap="round" fill="none">
                {c.cejas && <path d={c.cejas} />}
                <path d={c.boca} />
            </g>
        </svg>
    );
}

// ─── Una maqueta por extensión ───────────────────────────────────────────────

function SemaforoDeEmociones() {
    return (
        <>
            <Pantalla titulo="Conversaciones">
                <div className="divide-y divide-border/40">
                    <Fila inicial="PG" nombre="Pedro G." hora="09:02" antes={<Carita nivel="rojo" />}
                        texto="Llevo tres días con esto, voy a cancelar el servicio" />
                    <Fila inicial="NR" nombre="Nivaldo R." hora="09:01" antes={<Carita nivel="amarillo" />}
                        texto="Buenos días, ¿alguna novedad? Es la tercera vez que escribo" />
                    <Fila inicial="ML" nombre="Marta L." hora="08:55" antes={<Carita nivel="verde" />}
                        texto="Perfecto, muchas gracias por la ayuda" />
                </div>
            </Pantalla>
            <Pie>
                lee el tono de cada cliente y pinta la carita. Pedro sube a rojo porque habla de irse,
                no porque tenga una avería: lo que mide es quién necesita atención antes, no quién
                tiene un problema. Al pasar el ratón por encima se explica el motivo.
            </Pie>
        </>
    );
}

function SeguimientoSinRespuesta() {
    return (
        <>
            <Pantalla titulo="Conversaciones">
                <div className="divide-y divide-border/40">
                    <Fila inicial="CR" nombre="Camilo R." hora="08:10"
                        etiqueta={<Etiqueta color="#ea580c">Sin respuesta</Etiqueta>}
                        texto="¿Me confirman si quedó agendada la visita?" />
                    <Fila inicial="AS" nombre="Andrea S." hora="09:38" apagada
                        texto="Listo, ya quedó. Gracias" />
                </div>
            </Pantalla>

            <div className="mt-3 flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2.5">
                <Bell className="mt-0.5 size-4 shrink-0 text-warning" />
                <div className="text-xs">
                    <p className="font-semibold text-foreground">Camilo R. lleva 1 h 28 min esperando</p>
                    <p className="text-muted-foreground">Su último mensaje sigue sin respuesta</p>
                </div>
                <Clock className="ml-auto mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
            </div>

            <Pie>
                cada cinco minutos mira los chats abiertos cuyo último mensaje es del cliente. Al pasar
                el tiempo que configures avisa por la campana y, si quieres, les pone una etiqueta.
                Andrea no aparece porque ya se le contestó. No le escribe nada al cliente, y no vuelve
                a avisar del mismo chat hasta que pase otra vez la espera.
            </Pie>
        </>
    );
}

function EnrutadoPorPalabra() {
    return (
        <>
            <div className="grid gap-3 sm:grid-cols-[1fr_auto_1fr] sm:items-center">
                <Pantalla titulo="Llega un mensaje">
                    <div className="px-3 py-2.5">
                        <div className="max-w-[90%] rounded-lg rounded-tl-none bg-muted px-2.5 py-1.5 text-[13px] leading-snug text-foreground">
                            Buenas, quiero <span className="rounded bg-amber-300/70 px-1 font-semibold text-amber-950">cancelar</span> mi plan
                        </div>
                    </div>
                </Pantalla>

                <div className="hidden justify-center text-muted-foreground sm:flex">→</div>

                <Pantalla titulo="Queda así, al instante">
                    <Fila inicial="JM" nombre="Jorge M." hora="ahora"
                        etiqueta={<Etiqueta color="#be123c">Retención</Etiqueta>}
                        texto="Buenas, quiero cancelar mi plan" />
                    <div className="flex items-center gap-1.5 border-t border-border/40 px-3 py-2 text-[11px] text-muted-foreground">
                        <UserRound className="size-3.5" /> Asignado a <span className="font-semibold text-foreground">Laura</span>
                        <Bell className="ml-auto size-3.5 text-warning" />
                    </div>
                </Pantalla>
            </div>

            <Pie>
                tú defines las reglas: «si el cliente escribe <em>cancelar</em> → etiqueta Retención,
                asigna a Laura y avisa». Se aplica la primera regla que coincida, de arriba abajo, y
                sólo una vez por mensaje. No le responde nada al cliente ni le quita el chat a quien ya
                lo tuviera asignado.
            </Pie>
        </>
    );
}

function FirmaDelAgente() {
    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                <Pantalla titulo="Sin la extensión">
                    <div className="bg-[#efeae2] dark:bg-[#0b141a]">
                        <Burbuja>
                            <span className="font-semibold italic">Ana Restrepo:</span> Ya quedó agendada
                            su visita para mañana.
                        </Burbuja>
                    </div>
                    <p className="border-t px-3 py-2 text-[11px] text-muted-foreground">
                        Formato fijo, no se puede cambiar
                    </p>
                </Pantalla>

                <Pantalla titulo="Con tu plantilla" className="ring-1 ring-primary/30">
                    <div className="bg-[#efeae2] dark:bg-[#0b141a]">
                        <Burbuja>
                            Ya quedó agendada su visita para mañana.
                            <br />
                            <span className="text-[#667781] dark:text-white/70">— Ana, Integra Colombia</span>
                        </Burbuja>
                    </div>
                    <p className="border-t px-3 py-2 text-[11px] text-muted-foreground">
                        <code className="rounded bg-muted px-1">{'{agente}'}</code>,{' '}
                        <code className="rounded bg-muted px-1">{'{empresa}'}</code>, antes o después
                    </p>
                </Pantalla>
            </div>

            <Pie>
                decide cómo se firman los mensajes que escribe tu equipo. Al instalarla nace con el
                formato de la izquierda, así que encenderla no cambia nada hasta que edites la
                plantilla. No toca las plantillas aprobadas por Meta —las rechazaría— ni lo que envía
                el sistema solo: menús, respuestas automáticas y campañas salen sin firma.
            </Pie>
        </>
    );
}

function ResumenConIa() {
    return (
        <>
            <Pantalla titulo="Cabecera del chat">
                <div className="flex items-center gap-2 border-b border-border/40 px-3 py-2">
                    <div className="size-7 shrink-0 rounded-full bg-muted" />
                    <span className="text-[13px] font-semibold text-foreground">Camilo R.</span>
                    <span className="ml-auto flex items-center gap-1.5 rounded-lg bg-info px-2.5 py-1 text-[11px] font-bold text-info-foreground">
                        <Sparkles className="size-3" /> Resumir
                    </span>
                </div>

                <div className="border-b border-info/20 bg-info/5 px-3 py-2.5">
                    <div className="flex items-center gap-1.5">
                        <Sparkles className="size-3 shrink-0 text-info" />
                        <span className="text-[11px] font-bold text-foreground">Resumen de la conversación</span>
                    </div>
                    <p className="mt-1.5 text-xs leading-relaxed text-foreground">
                        Sin servicio desde el martes. Ya reinició el router y cambió el cable. Se le
                        prometió visita técnica para el jueves y nadie fue.
                    </p>
                    <p className="mt-2 text-[10px] font-black uppercase tracking-widest text-muted-foreground/60">
                        Queda pendiente
                    </p>
                    <ul className="mt-0.5 space-y-0.5">
                        <li className="flex gap-1.5 text-xs text-foreground">
                            <span className="text-warning">▸</span> Reagendar la visita y avisarle
                        </li>
                        <li className="flex gap-1.5 text-xs text-foreground">
                            <span className="text-warning">▸</span> Revisar si le corresponde descuento
                        </li>
                    </ul>
                </div>

                <div className="px-3 py-2 text-[11px] text-muted-foreground">
                    …y debajo, la conversación completa de siempre
                </div>
            </Pantalla>
            <Pie>
                lee el hilo y lo cuenta en cinco líneas. Es para los traspasos: el turno que entra, el
                compañero que libra, el cliente que vuelve tras una semana. Se guarda, así que abrirlo
                dos veces no cuesta dos consultas al modelo — sólo se rehace cuando alguien escribe.
                No le responde nada al cliente ni decide por el asesor: el hilo sigue justo debajo.
            </Pie>
        </>
    );
}

// ─── Versiones mini, para las tarjetas del catálogo ──────────────────────────
// Las tarjetas van en rejilla de hasta tres columnas, así que la maqueta entera
// no cabe. Estas son un resumen, no una versión encogida: enseñan UNA cosa —la
// que distingue a esa extensión de las demás— y se callan el resto, que para eso
// está la página de detalle. Sin pie de texto: la tarjeta ya tiene su
// descripción justo encima y repetirla sería ruido.

/** Marco común de las mini: alto fijo para que la rejilla no quede escalonada. */
function Mini({ children }) {
    return (
        <div className="mt-3 h-[76px] overflow-hidden rounded-lg border bg-background/60 px-2.5 py-2">
            {children}
        </div>
    );
}

/** Línea de chat reducida a lo imprescindible: marca, nombre y un retazo. */
function MiniFila({ antes, nombre, texto, className }) {
    return (
        <div className={clsx('flex items-center gap-1.5 py-[3px]', className)}>
            {antes}
            <span className="shrink-0 text-[11px] font-semibold text-foreground">{nombre}</span>
            <span className="truncate text-[11px] text-muted-foreground">{texto}</span>
        </div>
    );
}

const MINIS = {
    conversation_summary: () => (
        <Mini>
            <div className="flex items-center gap-1.5">
                <Sparkles className="size-3 shrink-0 text-info" />
                <span className="text-[11px] font-bold text-foreground">Resumen</span>
            </div>
            <p className="mt-1 line-clamp-2 text-[11px] leading-snug text-muted-foreground">
                Sin servicio desde el martes. Ya reinició el router. Se le prometió visita y nadie fue.
            </p>
            <div className="mt-1 flex gap-1.5 text-[11px] text-foreground">
                <span className="text-warning">▸</span>
                <span className="truncate">Reagendar la visita</span>
            </div>
        </Mini>
    ),

    sentiment_traffic_light: () => (
        <Mini>
            <MiniFila antes={<Carita nivel="rojo" className="size-3" />} nombre="Pedro G." texto="voy a cancelar…" />
            <MiniFila antes={<Carita nivel="amarillo" className="size-3" />} nombre="Nivaldo R." texto="¿alguna novedad?" />
            <MiniFila antes={<Carita nivel="verde" className="size-3" />} nombre="Marta L." texto="gracias por la ayuda" />
        </Mini>
    ),

    follow_up: () => (
        <Mini>
            <MiniFila nombre="Camilo R." texto="¿quedó agendada la visita?"
                antes={<Clock className="size-3 shrink-0 text-muted-foreground" />} />
            <div className="mt-1 flex items-center gap-1.5 rounded border border-warning/30 bg-warning/10 px-1.5 py-1">
                <Bell className="size-3 shrink-0 text-warning" />
                <span className="truncate text-[11px] font-semibold text-foreground">
                    Lleva 1 h 28 min esperando
                </span>
            </div>
        </Mini>
    ),

    keyword_routing: () => (
        <Mini>
            <div className="rounded rounded-tl-none bg-muted px-1.5 py-1 text-[11px] leading-tight">
                quiero <span className="rounded bg-amber-300/70 px-1 font-semibold text-amber-950">cancelar</span> mi plan
            </div>
            <div className="mt-1 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                <span className="shrink-0">↳</span>
                <Etiqueta color="#be123c">Retención</Etiqueta>
                <UserRound className="size-3 shrink-0" />
                <span className="truncate font-semibold text-foreground">Laura</span>
            </div>
        </Mini>
    ),

    agent_signature: () => (
        <Mini>
            <div className="flex h-full items-center justify-end rounded bg-[#efeae2] px-2 dark:bg-[#0b141a]">
                <div className="max-w-full rounded rounded-tr-none bg-[#d9fdd3] px-2 py-1 text-[11px] leading-tight text-[#111b21] dark:bg-[#005c4b] dark:text-white">
                    Ya quedó agendada su visita.
                    <br />
                    <span className="text-[#667781] dark:text-white/70">— Ana, Integra Colombia</span>
                </div>
            </div>
        </Mini>
    ),
};

// ─── El mapa ─────────────────────────────────────────────────────────────────

const MAQUETAS = {
    sentiment_traffic_light: SemaforoDeEmociones,
    follow_up: SeguimientoSinRespuesta,
    keyword_routing: EnrutadoPorPalabra,
    agent_signature: FirmaDelAgente,
    conversation_summary: ResumenConIa,
};

export function tieneMaqueta(slug) {
    return Boolean(MAQUETAS[slug]);
}

export function Maqueta({ slug }) {
    const Componente = MAQUETAS[slug];

    return Componente ? <Componente /> : null;
}

export function MaquetaMini({ slug }) {
    const Componente = MINIS[slug];

    return Componente ? <Componente /> : null;
}
