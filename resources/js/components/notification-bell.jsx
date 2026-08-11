import { useState, useEffect, useRef, useCallback } from 'react';
import { router } from '@inertiajs/react';
import { Bell, AtSign, CheckCheck, Megaphone, Trash2, X, Archive } from 'lucide-react';
import axios from 'axios';
import { clsx } from 'clsx';

export default function NotificationBell() {
    const [open, setOpen] = useState(false);
    const [unread, setUnread] = useState(0);
    const [items, setItems] = useState([]);
    const ref = useRef(null);

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
        if (!confirm('¿Eliminar todas las notificaciones?')) return;
        try {
            await axios.delete('/api/notifications');
            setItems([]);
            setUnread(0);
        } catch (_) {}
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
                <div className="absolute right-0 mt-2 w-80 max-h-[420px] overflow-y-auto bg-white dark:bg-[#2a3942] border border-border rounded-lg shadow-xl z-50">
                    <div className="flex items-center justify-between px-3 py-2 border-b border-border sticky top-0 bg-white dark:bg-[#2a3942]">
                        <span className="text-sm font-bold">Notificaciones</span>
                        <div className="flex items-center gap-3">
                            {unread > 0 && (
                                <button
                                    onClick={markAllRead}
                                    className="text-[11px] font-semibold text-teal-600 hover:text-teal-700 flex items-center gap-1"
                                >
                                    <CheckCheck className="size-3.5" /> Marcar leídas
                                </button>
                            )}
                            {items.length > 0 && (
                                <button
                                    onClick={deleteAll}
                                    className="text-[11px] font-semibold text-destructive hover:text-destructive/80 flex items-center gap-1"
                                >
                                    <Trash2 className="size-3.5" /> Eliminar todas
                                </button>
                            )}
                        </div>
                    </div>

                    {items.length === 0 ? (
                        <div className="px-3 py-8 text-center text-sm text-muted-foreground">
                            Sin notificaciones
                        </div>
                    ) : (
                        items.map(n => {
                            const isSystem = n.data?.type === 'system';
                            const isClosed = n.data?.type === 'conversation_closed';
                            return (
                                <div
                                    key={n.id}
                                    role="button"
                                    tabIndex={0}
                                    onClick={() => openNotification(n)}
                                    className={clsx(
                                        "group w-full text-left px-3 py-2.5 border-b border-border/50 transition-colors flex gap-2 cursor-pointer",
                                        n.read_at
                                            ? "opacity-60 hover:bg-muted"
                                            : isSystem
                                                ? "bg-sky-50/60 dark:bg-sky-900/10 hover:bg-sky-50 dark:hover:bg-sky-900/20"
                                                : isClosed
                                                    ? "bg-slate-100/70 dark:bg-slate-800/30 hover:bg-slate-100 dark:hover:bg-slate-800/50"
                                                    : "bg-amber-50/50 dark:bg-amber-900/10 hover:bg-amber-50 dark:hover:bg-amber-900/20"
                                    )}
                                >
                                    {isSystem ? (
                                        <Megaphone className="size-4 text-sky-600 shrink-0 mt-0.5" />
                                    ) : isClosed ? (
                                        <Archive className="size-4 text-slate-500 shrink-0 mt-0.5" />
                                    ) : (
                                        <AtSign className="size-4 text-amber-600 shrink-0 mt-0.5" />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        {isClosed ? (
                                            <p className="text-[13px] leading-snug">
                                                <span className="font-bold">{n.data?.by_name}</span>
                                                {n.data?.total > 1
                                                    ? ` cerró ${n.data.total} conversaciones`
                                                    : ' cerró una conversación'}
                                                {n.data?.contact_name && n.data?.total <= 1
                                                    ? <span className="text-muted-foreground"> · {n.data.contact_name}</span>
                                                    : null}
                                            </p>
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
