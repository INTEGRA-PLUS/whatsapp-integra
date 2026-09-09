import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    ArrowLeft, ArrowRight, Building2, Check, ExternalLink, Keyboard,
    Smartphone, TriangleAlert, X as XIcon,
} from 'lucide-react';

/**
 * Asistente de conexión por coexistencia.
 *
 * Antes esto era un botón suelto: el cliente lo pulsaba, se abría una ventana de
 * Meta sin contexto y la mitad de los intentos morían ahí. Los dos motivos eran
 * siempre los mismos —el paso previo en Meta Business Suite que nadie hace, y
 * buscar el número en la lista desplegable en vez de escribirlo— y ninguno de
 * los dos se puede adivinar mirando la pantalla de Meta.
 *
 * Ahora son cinco pasos que el cliente atraviesa antes de que se abra nada:
 *
 *   1. Qué le va a cambiar en el celular. La pantalla de consentimiento de Meta
 *      NO lo cuenta, y descubrirlo después es lo que genera la queja.
 *   2. Los tres requisitos, con casillas: sin marcarlas no se avanza.
 *   3. El portafolio comercial. Un ISP pequeño con una cuenta de Facebook
 *      normal no tiene ninguno, y toda la documentación —la nuestra incluida—
 *      daba por hecho que sí.
 *   4. Vincular la cuenta de WhatsApp Business al portafolio.
 *   5. Qué va a ver y qué tiene que hacer, incluido lo de escribir el número.
 *
 * El paso 5 es el que abre la ventana. Para entonces el cliente ya sabe qué
 * esperar, y el celular está en la mano.
 */
export default function AsistenteConexion({ open, onCancel, onLaunch }) {
    const [paso, setPaso] = useState(1);
    const [confirmado, setConfirmado] = useState({ appBusiness: false, antiguedad: false, celular: false });
    const [portafolio, setPortafolio] = useState(false);
    const [vinculada, setVinculada] = useState(false);

    const TOTAL = 5;

    // Cada apertura empieza limpia: si el asesor abandonó a mitad y vuelve con
    // otro cliente, lo que confirmó antes no debe darse por bueno.
    useEffect(() => {
        if (!open) return;
        setPaso(1);
        setConfirmado({ appBusiness: false, antiguedad: false, celular: false });
        setPortafolio(false);
        setVinculada(false);
    }, [open]);

    useEffect(() => {
        if (!open) return;
        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); onCancel?.(); }
        }
        document.addEventListener('keydown', onKey, true);
        return () => document.removeEventListener('keydown', onKey, true);
    }, [open, onCancel]);

    if (!open) return null;

    const requisitos = [
        {
            id: 'appBusiness',
            titulo: 'El número usa la app de WhatsApp Business',
            detalle: 'No la de WhatsApp normal. Si es la normal, no se puede conectar por este camino.',
        },
        {
            id: 'antiguedad',
            titulo: 'Lleva más de una semana en uso',
            detalle: 'Meta pide al menos 7 días de actividad real antes de aceptar una cuenta.',
        },
        {
            id: 'celular',
            titulo: 'Tengo el celular a la mano',
            detalle: 'A mitad del proceso hay que escanear un código QR con la cámara.',
        },
    ];

    const cambios = [
        ['Tus chats y tu historial', 'Se conservan, y hasta 6 meses se importan', true],
        ['Responder desde el celular', 'Sigue funcionando igual', true],
        ['Grupos', 'Siguen en el celular, no entran al CRM', false],
        ['Listas de difusión', 'Quedan de solo lectura', false],
        ['Mensajes temporales y ver una vez', 'Se desactivan en chats 1 a 1', false],
        ['WhatsApp Web y dispositivos vinculados', 'Se desconectan; se vuelven a vincular después', false],
    ];

    const puedeAvanzar =
        paso === 1 ? true
        : paso === 2 ? Object.values(confirmado).every(Boolean)
        : paso === 3 ? portafolio
        : paso === 4 ? vinculada
        : true;

    function siguiente() {
        if (paso < TOTAL) { setPaso(paso + 1); return; }
        onLaunch?.();
    }

    return (
        <div
            className="fixed inset-0 z-[200] flex items-center justify-center bg-black/70 p-4 animate-in fade-in duration-150"
            onClick={onCancel}
            role="dialog"
            aria-modal="true"
            aria-label="Asistente de conexión de WhatsApp Business"
        >
            <div
                className="flex max-h-[88vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-2xl animate-in zoom-in-105 duration-150 dark:bg-[#202c33]"
                onClick={e => e.stopPropagation()}
            >
                {/* Cabecera con el avance */}
                <div className="shrink-0 border-b px-5 py-4">
                    <div className="flex items-start gap-3">
                        <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/15 text-accent-foreground">
                            <Smartphone className="size-4.5" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <h2 className="text-[15px] font-bold leading-snug text-foreground">
                                Conectar el número que ya usas
                            </h2>
                            <p className="text-[12px] text-muted-foreground">Paso {paso} de {TOTAL}</p>
                        </div>
                        <button
                            onClick={onCancel}
                            className="-m-1 shrink-0 rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-black/5 dark:hover:bg-white/10"
                            aria-label="Cerrar"
                        >
                            <XIcon className="size-4" />
                        </button>
                    </div>

                    <div className="mt-3 flex gap-1.5">
                        {Array.from({ length: TOTAL }, (_, i) => (
                            <span
                                key={i}
                                className={`h-1 flex-1 rounded-full transition-colors ${
                                    i + 1 <= paso ? 'bg-primary' : 'bg-muted'
                                }`}
                            />
                        ))}
                    </div>
                </div>

                {/* Contenido */}
                <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    {paso === 1 && (
                        <>
                            <p className="text-[13px] leading-relaxed text-muted-foreground">
                                Seguirás respondiendo desde tu celular y no perderás ningún chat. Pero hay
                                cosas que cambian, y la pantalla de Meta no te las va a contar.
                            </p>

                            <div className="mt-3 overflow-hidden rounded-xl border border-border/60">
                                {cambios.map(([que, comoQueda, bueno], i) => (
                                    <div
                                        key={que}
                                        className={`flex items-start justify-between gap-4 px-3.5 py-2.5 text-[12.5px] ${i > 0 ? 'border-t border-border/50' : ''}`}
                                    >
                                        <span className="font-medium text-foreground">{que}</span>
                                        <span className={`shrink-0 text-right ${bueno ? 'text-success' : 'text-warning'}`}>
                                            {comoQueda}
                                        </span>
                                    </div>
                                ))}
                            </div>

                            <p className="mt-3 text-[12px] leading-relaxed text-muted-foreground">
                                El número queda además con un tope de <strong className="font-semibold">20 mensajes
                                por segundo</strong>, y tendrás que <strong className="font-semibold">abrir la app del
                                celular al menos una vez cada 14 días</strong> para que la conexión no se pause.
                            </p>
                        </>
                    )}

                    {paso === 2 && (
                        <>
                            <p className="text-[13px] leading-relaxed text-muted-foreground">
                                Si algo de esto no se cumple, Meta rechazará el número a mitad del proceso.
                                Mejor comprobarlo ahora.
                            </p>

                            <div className="mt-3 flex flex-col gap-1.5">
                                {requisitos.map(r => {
                                    const marcado = confirmado[r.id];
                                    return (
                                        <button
                                            key={r.id}
                                            type="button"
                                            onClick={() => setConfirmado(c => ({ ...c, [r.id]: !c[r.id] }))}
                                            aria-pressed={marcado}
                                            className={`flex w-full items-start gap-3 rounded-xl border px-3.5 py-2.5 text-left transition-colors ${
                                                marcado ? 'border-primary/50 bg-primary/10' : 'border-border/60 hover:bg-black/[.03] dark:hover:bg-white/[.04]'
                                            }`}
                                        >
                                            <span className={`mt-0.5 flex size-4 shrink-0 items-center justify-center rounded border ${
                                                marcado ? 'border-primary/30 bg-primary text-primary-foreground' : 'border-muted-foreground/50'
                                            }`}>
                                                {marcado && <Check className="size-3" strokeWidth={3} />}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-[13px] font-medium text-foreground">{r.titulo}</span>
                                                <span className="block text-[12px] leading-snug text-muted-foreground">{r.detalle}</span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </>
                    )}

                    {paso === 3 && (
                        <>
                            <div className="flex items-start gap-3 rounded-xl border border-border/60 px-3.5 py-3">
                                <Building2 className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                <p className="text-[12.5px] leading-relaxed text-muted-foreground">
                                    El <strong className="font-semibold text-foreground">portafolio comercial</strong> es
                                    tu empresa dentro de Meta: agrupa tu WhatsApp, tu página y quién puede
                                    administrarlos. Es gratis y se crea con tu cuenta de Facebook de siempre.
                                </p>
                            </div>

                            <p className="mt-3 text-[13px] font-medium text-foreground">Si todavía no tienes uno</p>
                            <ol className="mt-2 flex flex-col gap-2">
                                {[
                                    ['Entra a Meta Business Suite', 'Con tu cuenta de Facebook normal. No necesitas nada más.'],
                                    ['Abre el selector de arriba a la izquierda', 'Y elige «Crear un portafolio comercial».'],
                                    ['Llena los tres datos', 'Nombre de tu negocio, tu nombre y apellido, y un correo del negocio.'],
                                    ['Confirma el correo', 'Meta te manda un enlace. Sin abrirlo, el portafolio queda a medias.'],
                                ].map(([titulo, detalle], i) => (
                                    <li key={titulo} className="flex gap-3">
                                        <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-bold tabular-nums text-muted-foreground">
                                            {i + 1}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-[13px] font-medium text-foreground">{titulo}</span>
                                            <span className="block text-[12px] leading-snug text-muted-foreground">{detalle}</span>
                                        </span>
                                    </li>
                                ))}
                            </ol>

                            <div className="mt-3 rounded-xl border border-warning/40 bg-warning/10 px-3.5 py-3">
                                <p className="flex items-center gap-2 text-[12.5px] font-bold text-warning">
                                    <TriangleAlert className="size-4 shrink-0" />
                                    Completa la información del negocio
                                </p>
                                <p className="mt-1 text-[12px] leading-relaxed text-warning/90 dark:text-warning/90">
                                    Nombre legal, dirección, sitio web y teléfono. Meta puede restringir cuentas
                                    con esos datos incompletos, y suele hacerlo semanas después, cuando ya
                                    estás usando el número a diario.
                                </p>
                            </div>

                            <a
                                href="https://business.facebook.com/"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-3 inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-accent-foreground underline underline-offset-2 dark:text-accent-foreground"
                            >
                                Abrir Meta Business Suite en otra pestaña
                                <ExternalLink className="size-3" />
                            </a>

                            <button
                                type="button"
                                onClick={() => setPortafolio(v => !v)}
                                aria-pressed={portafolio}
                                className={`mt-3 flex w-full items-center gap-3 rounded-xl border px-3.5 py-3 text-left transition-colors ${
                                    portafolio ? 'border-primary/50 bg-primary/10' : 'border-border/60 hover:bg-black/[.03] dark:hover:bg-white/[.04]'
                                }`}
                            >
                                <span className={`flex size-4 shrink-0 items-center justify-center rounded border ${
                                    portafolio ? 'border-primary/30 bg-primary text-primary-foreground' : 'border-muted-foreground/50'
                                }`}>
                                    {portafolio && <Check className="size-3" strokeWidth={3} />}
                                </span>
                                <span className="text-[13px] font-medium text-foreground">
                                    Ya tengo mi portafolio comercial
                                </span>
                            </button>
                        </>
                    )}

                    {paso === 4 && (
                        <>
                            <div className="rounded-xl border border-warning/40 bg-warning/10 px-3.5 py-3">
                                <p className="flex items-center gap-2 text-[12.5px] font-bold text-warning">
                                    <TriangleAlert className="size-4 shrink-0" />
                                    Este es el paso que más se salta
                                </p>
                                <p className="mt-1 text-[12px] leading-relaxed text-warning/90 dark:text-warning/90">
                                    Tu cuenta de WhatsApp Business tiene que estar vinculada a tu portafolio de Meta
                                    <strong> antes</strong> de continuar. Si no, la ventana rechazará tu número
                                    diciendo que ya está registrado.
                                </p>
                            </div>

                            <p className="mt-3 text-[13px] leading-relaxed text-foreground/90">
                                En Meta Business Suite, con tu portafolio abierto:
                            </p>
                            <p className="mt-2 rounded-lg bg-muted/60 px-3 py-2 font-mono text-[11.5px] leading-relaxed text-muted-foreground">
                                Cuentas de WhatsApp → Agregar → <strong className="font-semibold text-foreground">Vincular una cuenta de WhatsApp Business</strong>
                            </p>
                            <p className="mt-2 text-[12px] leading-relaxed text-muted-foreground">
                                Ojo con las otras dos opciones del menú: <em>Crear una nueva</em> genera una cuenta
                                vacía y <em>Solicitar una para un cliente</em> es para otro negocio. Ninguna sirve aquí.
                            </p>

                            <img
                                src="/img/coexistencia/01-menu-agregar.jpg"
                                alt="Menú Agregar con la opción Vincular una cuenta de WhatsApp Business"
                                className="mt-3 w-full rounded-lg border bg-muted/30"
                                loading="lazy"
                            />

                            <a
                                href="https://business.facebook.com/latest/settings/whatsapp_account"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-3 inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-accent-foreground underline underline-offset-2 dark:text-accent-foreground"
                            >
                                Abrir Meta Business Suite en otra pestaña
                                <ExternalLink className="size-3" />
                            </a>

                            <button
                                type="button"
                                onClick={() => setVinculada(v => !v)}
                                aria-pressed={vinculada}
                                className={`mt-3 flex w-full items-center gap-3 rounded-xl border px-3.5 py-3 text-left transition-colors ${
                                    vinculada ? 'border-primary/50 bg-primary/10' : 'border-border/60 hover:bg-black/[.03] dark:hover:bg-white/[.04]'
                                }`}
                            >
                                <span className={`flex size-4 shrink-0 items-center justify-center rounded border ${
                                    vinculada ? 'border-primary/30 bg-primary text-primary-foreground' : 'border-muted-foreground/50'
                                }`}>
                                    {vinculada && <Check className="size-3" strokeWidth={3} />}
                                </span>
                                <span className="text-[13px] font-medium text-foreground">
                                    Ya vinculé mi cuenta en Meta Business Suite
                                </span>
                            </button>
                        </>
                    )}

                    {paso === 5 && (
                        <>
                            <p className="text-[13px] leading-relaxed text-muted-foreground">
                                Al continuar se abrirá una ventana de Facebook. Esto es lo que vas a ver:
                            </p>

                            <ol className="mt-3 flex flex-col gap-2.5">
                                {[
                                    ['Una pantalla informativa', 'Pulsa Continuar.'],
                                    ['«Agrega tu número de teléfono»', 'Aquí está la trampa. Ver abajo.'],
                                    ['Un código QR', 'A partir de ahí sigues en el celular: te llegará un mensaje de Facebook Business.'],
                                    ['Vuelta al computador', 'Confirmas el nombre, la zona horaria y los permisos.'],
                                ].map(([titulo, detalle], i) => (
                                    <li key={titulo} className="flex gap-3">
                                        <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-bold tabular-nums text-muted-foreground">
                                            {i + 1}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-[13px] font-medium text-foreground">{titulo}</span>
                                            <span className="block text-[12px] leading-snug text-muted-foreground">{detalle}</span>
                                        </span>
                                    </li>
                                ))}
                            </ol>

                            <div className="mt-4 rounded-xl border border-warning/40 bg-warning/10 px-3.5 py-3">
                                <p className="flex items-center gap-2 text-[12.5px] font-bold text-warning">
                                    <Keyboard className="size-4 shrink-0" />
                                    Escribe tu número, no lo busques en la lista
                                </p>
                                <p className="mt-1 text-[12px] leading-relaxed text-warning/90 dark:text-warning/90">
                                    En la pantalla del número, deja <strong>«Enter a new phone number»</strong> y
                                    escríbelo. Tu número <strong>no aparece</strong> en el desplegable: esa lista
                                    sólo trae números que ya están en la API.
                                </p>
                                <p className="mt-1.5 text-[12px] leading-relaxed text-warning/90 dark:text-warning/90">
                                    Al escribirlo, Meta reconoce que está en uso en tu celular y cambia solo al
                                    proceso correcto.
                                </p>
                            </div>

                            <p className="mt-3 text-[12px] text-muted-foreground">
                                Si tu navegador bloquea ventanas emergentes, permítelas para este sitio antes de continuar.
                            </p>
                        </>
                    )}
                </div>

                {/* Pie */}
                <div className="flex shrink-0 items-center justify-between gap-2 border-t px-5 py-3.5">
                    <Link
                        href="/instances/guia-coexistencia"
                        className="text-[12px] font-medium text-muted-foreground underline underline-offset-2 transition-colors hover:text-foreground"
                    >
                        Ver la guía completa
                    </Link>

                    <div className="flex items-center gap-2">
                        {paso > 1 && (
                            <button
                                onClick={() => setPaso(paso - 1)}
                                className="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-[13px] font-bold text-foreground transition-colors hover:bg-black/5 dark:hover:bg-white/10"
                            >
                                <ArrowLeft className="size-3.5" /> Atrás
                            </button>
                        )}
                        <button
                            onClick={siguiente}
                            disabled={!puedeAvanzar}
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-4 text-[13px] font-bold text-primary-foreground transition-colors hover:bg-primary disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {paso < TOTAL ? <>Continuar <ArrowRight className="size-3.5" /></> : 'Abrir ventana de conexión'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
