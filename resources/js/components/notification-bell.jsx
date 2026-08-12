import { useState, useEffect, useRef, useCallback } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Bell, AtSign, CheckCheck, Megaphone, Trash2, X, Archive, ShieldCheck, Loader2 } from 'lucide-react';
import axios from 'axios';
import { clsx } from 'clsx';
import { useConfirm } from '@/components/ui/confirm-dialog';
import { useNotificationRefresh, refreshConversations } from '@/lib/notifications';

/**
 * Antigüedad en lenguaje corto ("hace 5 min"). Pasado un día se muestra la
 * fecha, que a esa distancia dice más que "hace 3 d".
 */
function timeAgo(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const mins = Math.floor((Date.now() - date.getTime()) / 60000);

    if (mins < 1) return 'ahora mismo';
    if (mins < 60) return `hace ${mins} min`;
    if (mins < 1440) return `hace ${Math.floor(mins / 60)} h`;
    if (mins < 2880) return 'ayer';

    return date.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' });
}

export default function NotificationBell() {
    const [open, setOpen] = useState(false);
    const [unread, setUnread] = useState(0);
    const [items, setItems] = useState([]);
    const ref = useRef(null);
    // Ids ya recibidos por websocket, para no contarlos dos veces.
    const seenNotificationIds = useRef(new Set());
    // Para redactar en primera persona los avisos de acciones propias.
    const currentUserId = usePage().props?.auth?.user?.id;
    const { confirm, confirmDialog } = useConfirm();
    const [resolving, setResolving] = useState(null); // id de la notificación en curso
    const [error, setError] = useState(null);

    const load = useCallback(async () => {
        try {
            const res = await axios.get('/api/notifications');
            setUnread(res.data.unread_count ?? 0);
            setItems(res.data.notifications ?? []);
        } catch (_) {
            // silent — bell is non-critical
        }
    }, []);

    useEffect(() => {
        load();
        const id = setInterval(load, 25000);
        return () => clearInterval(id);
    }, [load]);

    // Tiempo real (Reverb): las notificaciones llegan por el canal privado del
    // propio usuario, así que la campana suma sin esperar al poll de 25s (que
    // queda de respaldo por si el websocket se cae).
    //
    // El payload viene con la misma forma que /api/notifications —{id, data,
    // read_at, created_at}— y el id es el mismo de la fila en base de datos, así
    // que cuando el poll la devuelva luego no se duplica.
    useEffect(() => {
        if (!currentUserId || !window.Echo) return;

        const channelName = `App.Models.User.${currentUserId}`;

        window.Echo.private(channelName).notification((n) => {
            // El contador se lleva aparte de la lista, así que la deduplicación
            // tiene que decidirse ANTES de tocar ninguno de los dos: si no, un
            // aviso repetido sumaría un no leído que no existe.
            if (!n?.id || seenNotificationIds.current.has(n.id)) return;
            seenNotificationIds.current.add(n.id);

            setItems(prev => [n, ...prev].slice(0, 30));
            setUnread(u => u + 1);
        });

        return () => window.Echo.leave(channelName);
    }, [currentUserId]);

    // Recarga inmediata cuando una acción del usuario acaba de generar una
    // notificación, en vez de esperar al siguiente poll.
    useNotificationRefresh(load);

    // Al volver a la pestaña, lo que muestre la campana puede llevar hasta 25
    // segundos de retraso; se refresca en cuanto vuelve a estar visible.
    useEffect(() => {
        function onVisible() {
            if (document.visibilityState === 'visible') load();
        }
        document.addEventListener('visibilitychange', onVisible);
        return () => document.removeEventListener('visibilitychange', onVisible);
    }, [load]);

    // Close on outside click
    useEffect(() => {
        function onClick(e) {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        }
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, []);

    async function markAllRead() {
        try {
            await axios.post('/api/notifications/read-all');
            setUnread(0);
            setItems(prev => prev.map(n => ({ ...n, read_at: new Date().toISOString() })));
        } catch (_) {}
    }

    async function deleteOne(n, e) {
        e?.stopPropagation();
        try {
            await axios.delete(`/api/notifications/${n.id}`);
            setItems(prev => prev.filter(x => x.id !== n.id));
            if (!n.read_at) setUnread(u => Math.max(0, u - 1));
        } catch (_) {}
    }

    async function deleteAll() {
        const ok = await confirm({
            title: '¿Eliminar todas las notificaciones?',
            description: `Se borrarán las ${items.length} notificaciones de la lista. Esta acción no se puede deshacer.`,
            confirmLabel: 'Eliminar todas',
        });
        if (!ok) return;
        try {
            await axios.delete('/api/notifications');
            setItems([]);
            setUnread(0);
        } catch (_) {}
    }

    /**
     * Resuelve una petición de eliminación sin salir de la campana.
     *
     * Al terminar se borra la notificación: ya está resuelta y dejarla ahí
     * invitaría a pulsar otra vez sobre algo que ya no existe.
     */
    async function resolveDeletion(n, action, e) {
        e?.stopPropagation();
        const requestId = n.data?.request_id;
        if (!requestId || resolving) return;

        setResolving(n.id);
        try {
            await axios.post(`/api/chat/deletion-requests/${requestId}/resolve`, { action });
            await axios.delete(`/api/notifications/${n.id}`).catch(() => {});
            await load();
            // La conversación pudo desaparecer: el chat abierto debe enterarse.
            refreshConversations();
        } catch (err) {
            setError(err?.response?.data?.error || 'No se pudo resolver la petición.');
            setTimeout(() => setError(null), 4000);
        } finally {
            setResolving(null);
        }
    }

    async function openNotification(n) {
        try {
            await axios.post(`/api/notifications/${n.id}/read`);
        } catch (_) {}
        setOpen(false);
        load();
        const convId = n.data?.conversation_id;
        const instanceId = n.data?.instance_id;
        if (convId) {
            router.visit(`/chat?conversation=${convId}${instanceId ? `&instance=${instanceId}` : ''}`);
        }
    }

    return (
        <div className="relative" ref={ref}>
            {confirmDialog}
            <button
                onClick={() => setOpen(o => !o)}
                className="relative p-1.5 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
                title="Notificaciones"
            >
                <Bell className="size-5" />
                {unread > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute right-0 mt-2 w-[23rem] max-w-[calc(100vw-1.5rem)] max-h-[440px] overflow-y-auto bg-white dark:bg-[#2a3942] border border-border rounded-xl shadow-xl z-50">
                    {/* Encabezado en dos filas: el título y las acciones no se
                        disputan el ancho, así que las etiquetas no se parten. */}
                    <div className="sticky top-0 z-10 bg-white dark:bg-[#2a3942] border-b border-border">
                        <div className="flex items-center gap-2 px-4 pt-3">
                            <span className="text-sm font-bold">Notificaciones</span>
                            {unread > 0 && (
                                <span className="min-w-[18px] h-[18px] px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">
                                    {unread > 99 ? '99+' : unread}
                                </span>
                            )}
                        </div>
                        {items.length > 0 && (
                            <div className="flex items-center justify-between gap-2 px-2 pt-1.5 pb-2">
                                {unread > 0 ? (
                                    <button
                                        onClick={markAllRead}
                                        className="text-[11px] font-semibold text-teal-600 hover:text-teal-700 hover:bg-teal-600/10 rounded-md px-2 py-1 flex items-center gap-1.5 whitespace-nowrap transition-colors"
                                    >
                                        <CheckCheck className="size-3.5 shrink-0" /> Marcar leídas
                                    </button>
                                ) : <span />}
                                <button
                                    onClick={deleteAll}
                                    className="text-[11px] font-semibold text-destructive hover:bg-destructive/10 rounded-md px-2 py-1 flex items-center gap-1.5 whitespace-nowrap transition-colors"
                                >
                                    <Trash2 className="size-3.5 shrink-0" /> Eliminar todas
                                </button>
                            </div>
                        )}
                    </div>

                    {error && (
                        <div className="mx-3 mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-[12px] text-rose-700 dark:text-rose-300">
                            {error}
                        </div>
                    )}

                    {items.length === 0 ? (
                        <div className="px-4 py-10 flex flex-col items-center gap-2 text-center">
                            <Bell className="size-7 text-muted-foreground/30" />
                            <p className="text-sm text-muted-foreground">Sin notificaciones</p>
                        </div>
                    ) : (
                        items.map(n => {
                            const isSystem = n.data?.type === 'system';
                            const isClosed = n.data?.type === 'conversation_closed';
                            const isRequest = n.data?.type === 'deletion_request';
                            const isResolved = n.data?.type === 'deletion_resolved';
                            return (
                                <div
                                    key={n.id}
                                    role="button"
                                    tabIndex={0}
                                    onClick={() => openNotification(n)}
                                    className={clsx(
                                        "group w-full text-left px-4 py-3 border-b border-border/50 last:border-b-0 transition-colors flex gap-2.5 cursor-pointer",
                                        n.read_at
                                            ? "opacity-60 hover:bg-muted"
                                            : isSystem
                                                ? "bg-sky-50/60 dark:bg-sky-900/10 hover:bg-sky-50 dark:hover:bg-sky-900/20"
                                                : isRequest
                                                ? "bg-rose-50/70 dark:bg-rose-900/10 hover:bg-rose-50 dark:hover:bg-rose-900/20"
                                                : isClosed || isResolved
                                                    ? "bg-slate-100/70 dark:bg-slate-800/30 hover:bg-slate-100 dark:hover:bg-slate-800/50"
                                                    : "bg-amber-50/50 dark:bg-amber-900/10 hover:bg-amber-50 dark:hover:bg-amber-900/20"
                                    )}
                                >
                                    {isSystem ? (
                                        <Megaphone className="size-4 text-sky-600 shrink-0 mt-0.5" />
                                    ) : isRequest ? (
                                        <Trash2 className="size-4 text-rose-600 shrink-0 mt-0.5" />
                                    ) : isResolved ? (
                                        n.data?.approved
                                            ? <Trash2 className="size-4 text-slate-500 shrink-0 mt-0.5" />
                                            : <ShieldCheck className="size-4 text-emerald-600 shrink-0 mt-0.5" />
                                    ) : isClosed ? (
                                        <Archive className="size-4 text-slate-500 shrink-0 mt-0.5" />
                                    ) : (
                                        <AtSign className="size-4 text-amber-600 shrink-0 mt-0.5" />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        {isRequest ? (
                                            <>
                                                <p className="text-[13px] leading-snug">
                                                    {n.data?.can_resolve === false
                                                        ? <><span className="font-bold">Pediste eliminar un chat</span> · pendiente de aprobación</>
                                                        : <><span className="font-bold">{n.data?.by_name}</span> pide eliminar un chat</>}
                                                </p>
                                                {n.data?.contact_name && (
                                                    <p className="text-[12px] text-muted-foreground truncate mt-0.5">
                                                        {n.data.contact_name}
                                                    </p>
                                                )}
                                                {n.data?.reason && (
                                                    <p className="text-[12px] text-muted-foreground/80 italic line-clamp-2 mt-0.5">
                                                        “{n.data.reason}”
                                                    </p>
                                                )}
                                                {/* Resolver aquí evita ir a buscar la conversación,
                                                    que además desaparece si se aprueba. Al
                                                    solicitante se le muestra la misma tarjeta sin
                                                    botones: puede seguirla, no autorizarla. */}
                                                <div className={clsx('flex items-center gap-1.5 mt-2', n.data?.can_resolve === false && 'hidden')}>
                                                    <button
                                                        onClick={(e) => resolveDeletion(n, 'approve', e)}
                                                        disabled={resolving === n.id}
                                                        className="h-7 px-2.5 rounded-md text-[11px] font-bold text-white bg-rose-600 hover:bg-rose-500 disabled:opacity-60 inline-flex items-center gap-1.5 transition-colors"
                                                    >
                                                        {resolving === n.id
                                                            ? <Loader2 className="size-3 animate-spin" />
                                                            : <Trash2 className="size-3" />}
                                                        Aprobar y eliminar
                                                    </button>
                                                    <button
                                                        onClick={(e) => resolveDeletion(n, 'reject', e)}
                                                        disabled={resolving === n.id}
                                                        className="h-7 px-2.5 rounded-md text-[11px] font-bold text-foreground hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-60 transition-colors"
                                                    >
                                                        Rechazar
                                                    </button>
                                                </div>
                                            </>
                                        ) : isResolved ? (
                                            <>
                                                <p className="text-[13px] leading-snug">
                                                    <span className="font-bold">{n.data?.by_name}</span>
                                                    {n.data?.approved
                                                        ? ' aprobó eliminar el chat'
                                                        : ' rechazó tu petición de eliminar el chat'}
                                                </p>
                                                {n.data?.contact_name && (
                                                    <p className="text-[12px] text-muted-foreground truncate mt-0.5">
                                                        {n.data.contact_name}
                                                    </p>
                                                )}
                                            </>
                                        ) : isClosed ? (
                                            <>
                                                <p className="text-[13px] leading-snug">
                                                    {n.data?.by_id && n.data.by_id === currentUserId
                                                        ? <span className="font-bold">Cerraste</span>
                                                        : <><span className="font-bold">{n.data?.by_name}</span> cerró</>}
                                                    {n.data?.total > 1
                                                        ? ` ${n.data.total} conversaciones`
                                                        : ' una conversación'}
                                                </p>
                                                {n.data?.contact_name && n.data?.total <= 1 && (
                                                    <p className="text-[12px] text-muted-foreground truncate mt-0.5">
                                                        {n.data.contact_name}
                                                    </p>
                                                )}
                                            </>
                                        ) : isSystem ? (
                                            <>
                                                <p className="text-[13px] leading-snug font-bold">{n.data?.title}</p>
                                                {n.data?.body && (
                                                    <p className="text-[12px] text-muted-foreground line-clamp-2 mt-0.5">{n.data.body}</p>
                                                )}
                                                {n.data?.by_name && (
                                                    <p className="text-[10px] text-muted-foreground/70 mt-1">— {n.data.by_name}</p>
                                                )}
                                            </>
                                        ) : (
                                            <>
                                                <p className="text-[13px] leading-snug">
                                                    <span className="font-bold">{n.data?.by_name}</span>
                                                    {' te mencionó'}
                                                    {n.data?.contact_name ? <span className="text-muted-foreground"> · {n.data.contact_name}</span> : null}
                                                </p>
                                                {n.data?.excerpt && (
                                                    <p className="text-[12px] text-muted-foreground truncate mt-0.5">{n.data.excerpt}</p>
                                                )}
                                            </>
                                        )}
                                        <p
                                            className="text-[10.5px] text-muted-foreground/70 mt-1"
                                            title={n.created_at ? new Date(n.created_at).toLocaleString('es-CO') : ''}
                                        >
                                            {timeAgo(n.created_at)}
                                        </p>
                                    </div>
                                    <button
                                        onClick={(e) => deleteOne(n, e)}
                                        title="Eliminar notificación"
                                        className="shrink-0 p-1 rounded text-muted-foreground/50 hover:text-destructive hover:bg-destructive/10 opacity-0 group-hover:opacity-100 transition-opacity self-start"
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                </div>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}
