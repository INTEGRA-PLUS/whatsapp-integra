import { useState, useRef, useEffect, useCallback } from 'react';
import { AlertTriangle, Loader2, X as XIcon } from 'lucide-react';
import { clsx } from 'clsx';

/**
 * Diálogo de confirmación con el estilo de los modales del chat.
 *
 * Sustituye a `window.confirm`, que bloquea el hilo, no se puede estilar y
 * aparece pegado al borde del navegador sin relación visual con la app.
 */
export function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel = 'Confirmar',
    cancelLabel = 'Cancelar',
    variant = 'danger', // 'danger' | 'default'
    loading = false,
    onConfirm,
    onCancel,
}) {
    const confirmRef = useRef(null);

    // El foco entra en el botón de confirmar para poder responder con Enter,
    // igual que hacía el confirm nativo.
    //
    // Se reintenta un par de veces porque el diálogo suele abrirse desde un
    // menú de Radix, que al cerrarse devuelve el foco a su disparador y nos lo
    // quitaría si solo lo pidiéramos una vez.
    useEffect(() => {
        if (!open) return;

        const timers = [0, 60, 150].map(delay =>
            setTimeout(() => {
                if (document.activeElement !== confirmRef.current) {
                    confirmRef.current?.focus();
                }
            }, delay)
        );

        return () => timers.forEach(clearTimeout);
    }, [open]);

    useEffect(() => {
        if (!open) return;
        function onKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                if (!loading) onCancel?.();
            }
        }
        // Captura: el chat también escucha Escape para deseleccionar el chat
        // abierto, y aquí tiene que ganar el diálogo.
        document.addEventListener('keydown', onKey, true);
        return () => document.removeEventListener('keydown', onKey, true);
    }, [open, loading, onCancel]);

    if (!open) return null;

    const isDanger = variant === 'danger';

    return (
        <div
            className="fixed inset-0 z-[200] flex items-center justify-center bg-black/70 p-4 animate-in fade-in duration-150"
            onClick={() => { if (!loading) onCancel?.(); }}
            role="alertdialog"
            aria-modal="true"
            aria-label={title}
        >
            <div
                className="w-full max-w-sm rounded-2xl bg-white dark:bg-[#202c33] shadow-2xl overflow-hidden animate-in zoom-in-105 duration-150"
                onClick={e => e.stopPropagation()}
            >
                <div className="flex items-start gap-3 px-5 pt-5">
                    <div className={clsx(
                        'size-10 rounded-full flex items-center justify-center shrink-0',
                        isDanger
                            ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400'
                            : 'bg-teal-500/15 text-teal-600 dark:text-teal-400',
                    )}>
                        <AlertTriangle className="size-5" />
                    </div>
                    <div className="min-w-0 flex-1 pt-0.5">
                        <h2 className="text-[15px] font-bold text-foreground leading-snug">{title}</h2>
                        {description && (
                            <p className="text-[13px] text-muted-foreground leading-relaxed mt-1.5 whitespace-pre-line">
                                {description}
                            </p>
                        )}
                    </div>
                    <button
                        onClick={() => { if (!loading) onCancel?.(); }}
                        className="shrink-0 p-1.5 -m-1 rounded-full text-muted-foreground hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
                        aria-label="Cerrar"
                    >
                        <XIcon className="size-4" />
                    </button>
                </div>

                <div className="flex items-center justify-end gap-2 px-5 py-4 mt-3">
                    <button
                        onClick={onCancel}
                        disabled={loading}
                        className="h-9 px-4 rounded-lg text-[13px] font-bold text-foreground hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-50 transition-colors"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        ref={confirmRef}
                        onClick={onConfirm}
                        disabled={loading}
                        className={clsx(
                            'h-9 px-4 rounded-lg text-[13px] font-bold text-white inline-flex items-center gap-2 disabled:opacity-60 transition-colors',
                            isDanger
                                ? 'bg-rose-600 hover:bg-rose-500'
                                : 'bg-teal-600 hover:bg-teal-500',
                        )}
                    >
                        {loading && <Loader2 className="size-3.5 animate-spin" />}
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

/**
 * Versión con promesa, para reemplazar `if (!confirm(...)) return;` sin
 * reestructurar el manejador:
 *
 *   const { confirm, confirmDialog } = useConfirm();
 *   ...
 *   if (!await confirm({ title: '¿Eliminar?' })) return;
 *   ...
 *   return (<>{confirmDialog}...</>);
 */
export function useConfirm() {
    const [state, setState] = useState(null);
    const resolverRef = useRef(null);

    const confirm = useCallback((options) => {
        // Una petición nueva cancela la anterior en vez de dejarla colgada.
        resolverRef.current?.(false);

        return new Promise(resolve => {
            resolverRef.current = resolve;
            setState(typeof options === 'string' ? { title: options } : options);
        });
    }, []);

    const settle = useCallback((answer) => {
        resolverRef.current?.(answer);
        resolverRef.current = null;
        setState(null);
    }, []);

    const confirmDialog = (
        <ConfirmDialog
            open={state !== null}
            {...(state || {})}
            onConfirm={() => settle(true)}
            onCancel={() => settle(false)}
        />
    );

    return { confirm, confirmDialog };
}

export default ConfirmDialog;
