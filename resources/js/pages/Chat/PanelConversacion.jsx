import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    X, Send, Paperclip, Image as ImageIcon, FileText, Download, Loader2,
    Clock, ExternalLink, Check, CheckCheck, AlertTriangle, Mic, MapPin, StickyNote,
} from 'lucide-react';
import { clsx } from 'clsx';
import { useAviso } from '@/components/ui/toast';

/**
 * El chat de una tarjeta, sin salir del tablero.
 *
 * Antes, hacer clic en una tarjeta no hacía nada: para contestar había que ir
 * al chat, buscar la conversación en una lista de tres mil y volver. Este panel
 * usa exactamente los mismos endpoints que el chat de verdad
 * (`/api/chat/conversations/{id}/messages`, `/send`, `/send-image`,
 * `/send-document`), así que lo que se manda desde aquí es lo mismo que se
 * manda desde allá; no hay una segunda vía de envío que mantener.
 *
 * Lo que no hace, a propósito: plantillas, notas internas, reacciones,
 * reenvíos. Cuando la ventana de 24 h está cerrada hace falta una plantilla, y
 * para eso el panel manda al chat completo en vez de duplicar ese selector.
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

const HORA = { hour: '2-digit', minute: '2-digit' };
const DIA = { weekday: 'long', day: 'numeric', month: 'long' };

/** La hora que Meta cuenta es `sent_at`; `created_at` es cuándo lo guardamos. */
function cuando(msg) {
    return new Date(msg.sent_at || msg.created_at);
}

function urlDelArchivo(msg, enLinea = false) {
    return `/api/chat/messages/${msg.id}/media${enLinea ? '?inline=1' : ''}`;
}

function Adjunto({ msg }) {
    const nombre = msg.filename || 'archivo';
    const [imagenCaida, setImagenCaida] = useState(false);

    if ((msg.type === 'image' || msg.type === 'sticker') && !imagenCaida) {
        return (
            <a href={urlDelArchivo(msg, true)} target="_blank" rel="noreferrer" className="block">
                <img
                    src={urlDelArchivo(msg, true)}
                    alt={msg.content || 'Imagen enviada por WhatsApp'}
                    loading="lazy"
                    // Meta borra los adjuntos 30 días después del envío. Sin
                    // esto quedaba el icono de imagen rota del navegador, que no
                    // explica nada; el chat completo ya avisa de la caducidad.
                    onError={() => setImagenCaida(true)}
                    className="max-h-56 w-full rounded-xl object-cover"
                />
            </a>
        );
    }

    if (msg.type === 'image' || msg.type === 'sticker') {
        return (
            <div className="flex items-center gap-2.5 rounded-xl bg-black/5 px-2.5 py-2 dark:bg-white/10">
                <ImageIcon className="size-4 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-[12px] font-bold text-foreground">
                        {msg.filename || 'Imagen'}
                    </span>
                    <span className="block text-[10.5px] text-muted-foreground">
                        No se pudo cargar; WhatsApp borra los adjuntos al mes
                    </span>
                </span>
            </div>
        );
    }

    if (msg.type === 'audio' || msg.type === 'voice') {
        return (
            <div className="flex items-center gap-2 rounded-xl bg-black/5 px-2.5 py-2 dark:bg-white/10">
                <Mic className="size-3.5 shrink-0 text-muted-foreground" />
                {/* Sin `controls` no se puede escuchar, y con el reproductor del
                    navegador basta: el chat completo tiene su propia onda. */}
                <audio controls preload="none" src={urlDelArchivo(msg, true)} className="h-8 w-full" />
            </div>
        );
    }

    if (msg.type === 'location') {
        return (
            <div className="flex items-center gap-2 rounded-xl bg-black/5 px-2.5 py-2 dark:bg-white/10">
                <MapPin className="size-3.5 shrink-0 text-muted-foreground" />
                <span className="text-[12px] font-bold">Ubicación compartida</span>
            </div>
        );
    }

    // Documentos: PDF, recibos, comprobantes. Es el caso que más se ve en el
    // tablero, así que se enseña el nombre completo y se puede abrir.
    return (
        <a
            href={urlDelArchivo(msg, true)}
            target="_blank"
            rel="noreferrer"
            className="flex items-center gap-2.5 rounded-xl bg-black/5 px-2.5 py-2 transition-colors hover:bg-black/10 dark:bg-white/10 dark:hover:bg-white/15"
        >
            <FileText className="size-4 shrink-0 text-muted-foreground" />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-[12px] font-bold text-foreground">{nombre}</span>
                <span className="block text-[10.5px] text-muted-foreground">
                    {msg.media_mime_type || 'Documento'}
                </span>
            </span>
            <Download className="size-3.5 shrink-0 text-muted-foreground" />
        </a>
    );
}

function Estado({ msg }) {
    if (msg.direction !== 'outbound') return null;
    if (msg.status === 'failed') return <AlertTriangle className="size-3 text-destructive" />;
    if (msg.read_at) return <CheckCheck className="size-3 text-info" />;
    if (msg.delivered_at) return <CheckCheck className="size-3 opacity-60" />;
    return <Check className="size-3 opacity-60" />;
}

function Burbuja({ msg }) {
    const mio = msg.direction === 'outbound';
    const conArchivo = !['text', 'template', 'interactive', 'button', 'unsupported'].includes(msg.type);

    return (
        <div className={clsx('flex', mio ? 'justify-end' : 'justify-start')}>
            <div className={clsx(
                'max-w-[85%] rounded-2xl px-2.5 py-1.5 shadow-sm',
                msg.is_internal
                    ? 'bg-warning/15 text-foreground'
                    : mio
                        ? 'bg-[#d9fdd3] text-[#111b21] dark:bg-[#005c4b] dark:text-white'
                        : 'bg-white text-[#111b21] dark:bg-[#202c33] dark:text-white',
            )}>
                {msg.is_internal && (
                    <p className="mb-1 flex items-center gap-1 text-[10px] font-black uppercase tracking-wider text-warning">
                        <StickyNote className="size-2.5" /> Nota interna
                    </p>
                )}

                {conArchivo && <div className="mb-1"><Adjunto msg={msg} /></div>}

                {msg.content && (
                    <p className="whitespace-pre-wrap break-words text-[13.5px] leading-snug">{msg.content}</p>
                )}

                <p className={clsx(
                    'mt-0.5 flex items-center justify-end gap-1 text-[10px] tabular-nums',
                    mio ? 'text-black/45 dark:text-white/65' : 'text-black/45 dark:text-white/65',
                )}>
                    {msg.sender?.name && <span className="mr-auto font-bold">{msg.sender.name}</span>}
                    {cuando(msg).toLocaleTimeString('es-CO', HORA)}
                    <Estado msg={msg} />
                </p>
            </div>
        </div>
    );
}

export default function PanelConversacion({ conversacionId, resumen, onCerrar, onCambio }) {
    const [mensajes, setMensajes] = useState([]);
    const [conversacion, setConversacion] = useState(null);
    const [cargando, setCargando] = useState(true);
    const [hayMas, setHayMas] = useState(false);
    const [cargandoMas, setCargandoMas] = useState(false);
    const [texto, setTexto] = useState('');
    const [enviando, setEnviando] = useState(false);
    const finRef = useRef(null);
    const listaRef = useRef(null);
    const imagenRef = useRef(null);
    const documentoRef = useRef(null);
    const aviso = useAviso();

    const pedir = useCallback(async (url, opciones = {}) => {
        const res = await fetch(url, {
            ...opciones,
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                ...(opciones.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
                ...(opciones.headers ?? {}),
            },
        });
        const datos = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(datos.message || datos.error || `HTTP ${res.status}`);
        return datos;
    }, []);

    // Historial
    useEffect(() => {
        let vivo = true;
        setCargando(true);
        setMensajes([]);

        pedir(`/api/chat/conversations/${conversacionId}/messages`)
            .then(datos => {
                if (!vivo) return;
                setMensajes(datos.messages ?? []);
                setConversacion(datos.conversation ?? null);
                setHayMas(!!datos.has_more);
            })
            .catch(err => { if (vivo) aviso.error('No se pudo abrir la conversación', { detalle: err.message }); })
            .finally(() => { if (vivo) setCargando(false); });

        return () => { vivo = false; };
    }, [conversacionId, pedir]); // eslint-disable-line react-hooks/exhaustive-deps

    // Al abrir y al enviar, el final del hilo. Sin esto el panel se abre en el
    // mensaje más viejo de la ventana, que no es lo que hace falta contestar.
    useEffect(() => {
        if (!cargando) finRef.current?.scrollIntoView({ block: 'end' });
    }, [cargando, mensajes.length]);

    const cerrarConEscape = useCallback(e => { if (e.key === 'Escape') onCerrar(); }, [onCerrar]);
    useEffect(() => {
        document.addEventListener('keydown', cerrarConEscape);
        return () => document.removeEventListener('keydown', cerrarConEscape);
    }, [cerrarConEscape]);

    const ventanaCerrada = useMemo(() => {
        const ultimoEntrante = [...mensajes].reverse().find(m => m.direction === 'inbound');
        if (!ultimoEntrante) return true;
        return Date.now() - cuando(ultimoEntrante).getTime() > 24 * 60 * 60 * 1000;
    }, [mensajes]);

    const cargarMas = async () => {
        if (cargandoMas || mensajes.length === 0) return;
        setCargandoMas(true);
        const alto = listaRef.current?.scrollHeight ?? 0;
        try {
            const datos = await pedir(
                `/api/chat/conversations/${conversacionId}/messages?before_id=${mensajes[0].id}`
            );
            setMensajes(prev => [...(datos.messages ?? []), ...prev]);
            setHayMas(!!datos.has_more);
            // Se mantiene la vista donde estaba: si no, insertar arriba empuja
            // el hilo y se pierde de vista lo que se estaba leyendo.
            requestAnimationFrame(() => {
                if (listaRef.current) listaRef.current.scrollTop = listaRef.current.scrollHeight - alto;
            });
        } catch (err) {
            aviso.error('No se pudo cargar el historial anterior', { detalle: err.message });
        } finally {
            setCargandoMas(false);
        }
    };

    const enviar = async () => {
        const cuerpo = texto.trim();
        if (!cuerpo || enviando) return;

        setEnviando(true);
        try {
            const datos = await pedir(`/api/chat/conversations/${conversacionId}/send`, {
                method: 'POST',
                body: JSON.stringify({ message: cuerpo }),
            });
            setTexto('');
            if (datos.message) setMensajes(prev => [...prev, datos.message]);
            onCambio?.();
        } catch (err) {
            aviso.error('No se pudo enviar el mensaje', { detalle: err.message });
        } finally {
            setEnviando(false);
        }
    };

    const enviarArchivo = async (archivo, tipo) => {
        if (!archivo) return;
        setEnviando(true);
        const cuerpo = new FormData();
        cuerpo.append(tipo === 'imagen' ? 'image' : 'document', archivo);
        try {
            const datos = await pedir(
                `/api/chat/conversations/${conversacionId}/${tipo === 'imagen' ? 'send-image' : 'send-document'}`,
                { method: 'POST', body: cuerpo },
            );
            if (datos.message) setMensajes(prev => [...prev, datos.message]);
            aviso.exito(tipo === 'imagen' ? 'Imagen enviada' : `${archivo.name} enviado`);
            onCambio?.();
        } catch (err) {
            aviso.error('No se pudo enviar el archivo', { detalle: err.message });
        } finally {
            setEnviando(false);
        }
    };

    const nombre = conversacion?.name || resumen?.name || resumen?.phone_number || 'Conversación';
    const telefono = conversacion?.phone_number || resumen?.phone_number;

    // Los mensajes se agrupan por día, como en WhatsApp: sin el separador, un
    // hilo de meses es una lista de horas sin fecha.
    const porDia = useMemo(() => {
        const grupos = [];
        for (const m of mensajes) {
            const clave = cuando(m).toDateString();
            if (grupos.length === 0 || grupos.at(-1).clave !== clave) {
                grupos.push({ clave, fecha: cuando(m), mensajes: [m] });
            } else {
                grupos.at(-1).mensajes.push(m);
            }
        }
        return grupos;
    }, [mensajes]);

    return (
        <>
            {/* El velo cierra al pulsar fuera y, en móvil, tapa el tablero. */}
            <div
                onClick={onCerrar}
                className="fixed inset-0 z-40 bg-black/40 backdrop-blur-[2px] animate-in fade-in duration-150"
            />

            <aside
                role="dialog"
                aria-label={`Conversación con ${nombre}`}
                className="fixed right-0 top-0 z-50 flex h-svh w-full max-w-[27rem] flex-col border-l border-border bg-[#f0f2f5] shadow-2xl animate-in slide-in-from-right duration-200 dark:bg-[#0b141a]"
            >
                {/* Cabecera */}
                <div className="flex shrink-0 items-center gap-2.5 border-b border-border bg-card px-3 py-2.5">
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-xl border border-border/50 bg-muted text-[11px] font-black uppercase text-muted-foreground">
                        {(resumen?.initials || nombre).slice(0, 2)}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-black leading-tight text-foreground">{nombre}</p>
                        <p className="truncate text-[11px] leading-tight text-muted-foreground">
                            {telefono}
                            {resumen?.assigned_agent?.name && ` · ${resumen.assigned_agent.name}`}
                        </p>
                    </div>
                    <a
                        href={`/chat?conversation=${conversacionId}`}
                        className="flex shrink-0 items-center gap-1.5 rounded-xl border border-border px-2.5 py-1.5 text-[11px] font-bold text-muted-foreground transition-colors hover:border-primary/40 hover:text-accent-foreground"
                    >
                        <ExternalLink className="size-3.5" /> Chat completo
                    </a>
                    <button
                        onClick={onCerrar}
                        aria-label="Cerrar la conversación"
                        className="shrink-0 rounded-xl p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <X className="size-4" />
                    </button>
                </div>

                {/* Hilo */}
                <div ref={listaRef} className="flex-1 space-y-2 overflow-y-auto px-3 py-3">
                    {cargando && (
                        <div className="flex h-full items-center justify-center">
                            <Loader2 className="size-5 animate-spin text-muted-foreground" />
                        </div>
                    )}

                    {!cargando && hayMas && (
                        <button
                            onClick={cargarMas}
                            disabled={cargandoMas}
                            className="mx-auto flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1 text-[11px] font-bold text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
                        >
                            {cargandoMas ? <Loader2 className="size-3 animate-spin" /> : <Clock className="size-3" />}
                            Ver mensajes anteriores
                        </button>
                    )}

                    {!cargando && mensajes.length === 0 && (
                        <p className="mt-8 text-center text-[12px] text-muted-foreground">
                            Esta conversación todavía no tiene mensajes.
                        </p>
                    )}

                    {porDia.map(grupo => (
                        <div key={grupo.clave} className="space-y-2">
                            <p className="mx-auto w-fit rounded-full bg-black/5 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-muted-foreground dark:bg-white/10">
                                {grupo.fecha.toLocaleDateString('es-CO', DIA)}
                            </p>
                            {grupo.mensajes.map(m => <Burbuja key={m.id} msg={m} />)}
                        </div>
                    ))}

                    <div ref={finRef} />
                </div>

                {/* Responder */}
                <div className="shrink-0 border-t border-border bg-card px-3 py-2.5">
                    {ventanaCerrada ? (
                        <div className="flex items-start gap-2 rounded-xl border border-warning/30 bg-warning/10 px-3 py-2.5">
                            <Clock className="mt-px size-4 shrink-0 text-warning" />
                            <p className="text-[11.5px] leading-snug text-foreground">
                                La ventana de 24&nbsp;h está cerrada: hay que reabrir con una{' '}
                                <b>plantilla aprobada</b>.{' '}
                                <a
                                    href={`/chat?conversation=${conversacionId}`}
                                    className="font-bold text-accent-foreground underline underline-offset-2"
                                >
                                    Enviar una plantilla
                                </a>
                            </p>
                        </div>
                    ) : (
                        <div className="flex items-end gap-1.5">
                            <input
                                ref={imagenRef}
                                type="file"
                                accept="image/*"
                                hidden
                                onChange={e => { enviarArchivo(e.target.files?.[0], 'imagen'); e.target.value = ''; }}
                            />
                            <input
                                ref={documentoRef}
                                type="file"
                                hidden
                                onChange={e => { enviarArchivo(e.target.files?.[0], 'documento'); e.target.value = ''; }}
                            />

                            <button
                                onClick={() => imagenRef.current?.click()}
                                disabled={enviando}
                                aria-label="Enviar una imagen"
                                className="shrink-0 rounded-xl p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
                            >
                                <ImageIcon className="size-4" />
                            </button>
                            <button
                                onClick={() => documentoRef.current?.click()}
                                disabled={enviando}
                                aria-label="Enviar un documento"
                                className="shrink-0 rounded-xl p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
                            >
                                <Paperclip className="size-4" />
                            </button>

                            <textarea
                                rows={1}
                                value={texto}
                                onChange={e => setTexto(e.target.value)}
                                onKeyDown={e => {
                                    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviar(); }
                                }}
                                placeholder="Escribe un mensaje…"
                                className="max-h-28 min-h-[38px] flex-1 resize-none rounded-xl border border-border bg-background px-3 py-2 text-[13px] text-foreground outline-none placeholder:text-muted-foreground focus:border-primary/40 focus:ring-0"
                            />

                            <button
                                onClick={enviar}
                                disabled={enviando || !texto.trim()}
                                className="flex h-[38px] shrink-0 items-center gap-1.5 rounded-xl bg-primary px-3 text-[12px] font-black text-primary-foreground transition-transform hover:scale-[1.02] active:scale-95 disabled:opacity-40 disabled:hover:scale-100"
                            >
                                {enviando ? <Loader2 className="size-3.5 animate-spin" /> : <Send className="size-3.5" />}
                                Enviar
                            </button>
                        </div>
                    )}
                </div>
            </aside>
        </>
    );
}
