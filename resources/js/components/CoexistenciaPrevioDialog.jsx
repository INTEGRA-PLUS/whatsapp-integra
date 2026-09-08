import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Check, ExternalLink, Smartphone, X as XIcon } from 'lucide-react';

/**
 * Lo que hay que saber ANTES de conectar un número que ya se usa a diario.
 *
 * Existe por dos motivos distintos y los dos cuestan dinero:
 *
 * 1. La pantalla de consentimiento de Meta cubre compartir el historial, pero
 *    NO menciona que se desactivan los mensajes temporales, que las listas de
 *    difusión quedan de solo lectura ni que se desconectan los dispositivos
 *    vinculados —WhatsApp Web incluido— en mitad del proceso. Quien pulsa tiene
 *    que saberlo antes, no descubrirlo con el celular del cliente en la mano.
 *
 * 2. El paso previo en Meta Business Suite y el requisito de los 7 días son la
 *    causa de casi todos los intentos fallidos. Preguntarlos aquí, en tres
 *    casillas, ahorra una llamada a soporte por cada cliente.
 *
 * Antes esto vivía en un `<details>` colapsado debajo del botón, que es como no
 * tenerlo.
 */
export default function CoexistenciaPrevioDialog({ open, onCancel, onConfirm }) {
    const [confirmado, setConfirmado] = useState({ appBusiness: false, antiguedad: false, celular: false });

    // Cada apertura empieza limpia: si el asesor abandonó a mitad y vuelve con
    // otro cliente, las casillas de antes no deben darse por buenas.
    useEffect(() => {
        if (open) setConfirmado({ appBusiness: false, antiguedad: false, celular: false });
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

    const todo = Object.values(confirmado).every(Boolean);

    const requisitos = [
        {
            id: 'appBusiness',
            titulo: 'El número usa la app de WhatsApp Business',
            detalle: 'No la de WhatsApp normal. Si es la normal, el número no se puede conectar por este camino.',
        },
        {
            id: 'antiguedad',
            titulo: 'Lleva más de una semana en uso',
            detalle: 'Meta pide al menos 7 días de actividad real antes de considerar elegible una cuenta.',
        },
        {
            id: 'celular',
            titulo: 'Tienes el celular a la mano',
            detalle: 'A mitad del proceso hay que escanear un código QR con la cámara del teléfono.',
        },
    ];

    const cambios = [
        ['Chats individuales', 'Siguen igual en el celular', true],
        ['Historial de hasta 6 meses', 'Se importa al CRM', true],
        ['Grupos', 'Siguen en el celular, no entran al CRM', false],
        ['Listas de difusión', 'Quedan de solo lectura', false],
        ['Mensajes temporales y ver una vez', 'Se desactivan en chats 1 a 1', false],
        ['Dispositivos vinculados', 'Se desconectan; se vuelven a vincular después', false],
    ];

    return (
        <div
            className="fixed inset-0 z-[200] flex items-center justify-center bg-black/70 p-4 animate-in fade-in duration-150"
            onClick={onCancel}
            role="dialog"
            aria-modal="true"
            aria-label="Qué implica conectar tu WhatsApp Business actual"
        >
            <div
                className="w-full max-w-lg max-h-[88vh] overflow-y-auto rounded-2xl bg-white dark:bg-[#202c33] shadow-2xl animate-in zoom-in-105 duration-150"
                onClick={e => e.stopPropagation()}
            >
                <div className="flex items-start gap-3 px-5 pt-5">
                    <div className="size-10 shrink-0 rounded-full bg-teal-500/15 text-teal-600 dark:text-teal-400 flex items-center justify-center">
                        <Smartphone className="size-5" />
                    </div>
                    <div className="min-w-0 flex-1 pt-0.5">
                        <h2 className="text-[15px] font-bold leading-snug text-foreground">
                            Conectar el número que ya usas
                        </h2>
                        <p className="mt-1.5 text-[13px] leading-relaxed text-muted-foreground">
                            Seguirás respondiendo desde tu celular y no perderás ningún chat. Pero hay cosas
                            que cambian, y conviene saberlas antes.
                        </p>
                    </div>
                    <button
                        onClick={onCancel}
                        className="-m-1 shrink-0 rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-black/5 dark:hover:bg-white/10"
                        aria-label="Cerrar"
                    >
                        <XIcon className="size-4" />
                    </button>
                </div>

                {/* Qué cambia */}
                <div className="mt-4 px-5">
                    <div className="overflow-hidden rounded-xl border border-border/60">
                        {cambios.map(([que, comoQueda, bueno], i) => (
                            <div
                                key={que}
                                className={`flex items-start justify-between gap-4 px-3.5 py-2.5 text-[12.5px] ${
                                    i > 0 ? 'border-t border-border/50' : ''
                                }`}
                            >
                                <span className="font-medium text-foreground">{que}</span>
                                <span className={`shrink-0 text-right ${bueno ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'}`}>
                                    {comoQueda}
                                </span>
                            </div>
                        ))}
                    </div>

                    <p className="mt-2.5 text-[12px] leading-relaxed text-muted-foreground">
                        El número queda además con un tope de <strong>20 mensajes por segundo</strong>, y hay que
                        <strong> abrir la app del celular al menos una vez cada 14 días</strong> para que la conexión
                        no se pause.
                    </p>
                </div>

                {/* Requisitos */}
                <div className="mt-5 px-5">
                    <p className="text-[11.5px] font-bold uppercase tracking-wide text-muted-foreground">
                        Antes de continuar, confirma
                    </p>
                    <div className="mt-2 flex flex-col gap-1.5">
                        {requisitos.map(r => {
                            const marcado = confirmado[r.id];
                            return (
                                <button
                                    key={r.id}
                                    type="button"
                                    onClick={() => setConfirmado(c => ({ ...c, [r.id]: !c[r.id] }))}
                                    aria-pressed={marcado}
                                    className={`flex w-full items-start gap-3 rounded-xl border px-3.5 py-2.5 text-left transition-colors ${
                                        marcado
                                            ? 'border-teal-500/50 bg-teal-500/10'
                                            : 'border-border/60 hover:bg-black/[.03] dark:hover:bg-white/[.04]'
                                    }`}
                                >
                                    <span className={`mt-0.5 flex size-4 shrink-0 items-center justify-center rounded border ${
                                        marcado ? 'border-teal-600 bg-teal-600 text-white' : 'border-muted-foreground/50'
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
                </div>

                {/* El paso que más se salta */}
                <div className="mt-4 px-5">
                    <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 px-3.5 py-3">
                        <p className="text-[12.5px] font-bold text-amber-700 dark:text-amber-400">
                            Falta un paso previo en Meta Business Suite
                        </p>
                        <p className="mt-1 text-[12px] leading-relaxed text-amber-700/90 dark:text-amber-400/90">
                            Tu cuenta de WhatsApp Business tiene que estar vinculada a tu portafolio comercial
                            <strong> antes</strong> de empezar aquí. Si no, esta ventana rechazará el número diciendo
                            que ya está registrado.
                        </p>
                        <Link
                            href="/instances/guia-coexistencia"
                            className="mt-2 inline-flex items-center gap-1.5 text-[12px] font-semibold text-amber-700 underline underline-offset-2 dark:text-amber-400"
                        >
                            Ver la guía completa con capturas
                            <ExternalLink className="size-3" />
                        </Link>
                    </div>
                </div>

                <div className="mt-5 flex items-center justify-end gap-2 px-5 py-4">
                    <button
                        onClick={onCancel}
                        className="h-9 rounded-lg px-4 text-[13px] font-bold text-foreground transition-colors hover:bg-black/5 dark:hover:bg-white/10"
                    >
                        Cancelar
                    </button>
                    <button
                        onClick={onConfirm}
                        disabled={!todo}
                        className="h-9 rounded-lg bg-teal-600 px-4 text-[13px] font-bold text-white transition-colors hover:bg-teal-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Entendido, conectar
                    </button>
                </div>
            </div>
        </div>
    );
}
