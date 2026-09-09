// Vocabulario común de las tres pantallas de campañas (lista, asistente y
// detalle). Estaba copiado literalmente en dos de ellas, así que un estado
// nuevo salía con un nombre distinto según dónde se mirara.

export const STATUS_LABEL = {
    draft: 'Borrador',
    queued: 'En cola',
    sending: 'Enviando',
    paused: 'Pausada',
    completed: 'Completada',
    cancelled: 'Cancelada',
    failed: 'Fallida',
};

export const STATUS_CLASS = {
    draft: 'bg-muted text-muted-foreground',
    queued: 'bg-info/15 text-info dark:bg-info/30 dark:text-info',
    sending: 'bg-warning/15 text-warning dark:bg-warning/30 dark:text-warning',
    paused: 'bg-warning/15 text-warning dark:bg-warning/30 dark:text-warning',
    completed: 'bg-success/15 text-success dark:bg-success/30 dark:text-success',
    cancelled: 'bg-muted text-muted-foreground',
    failed: 'bg-destructive/15 text-destructive dark:bg-destructive/30 dark:text-destructive',
};

/** Estado de cada destinatario, que no es el mismo que el de la campaña. */
export const RECIPIENT_LABEL = {
    pending: 'Pendiente',
    sending: 'Enviando',
    sent: 'Enviado',
    delivered: 'Entregado',
    read: 'Leído',
    failed: 'Fallido',
    skipped: 'Omitido',
};

export const RECIPIENT_CLASS = {
    pending: 'bg-muted text-muted-foreground',
    sending: 'bg-info/15 text-info dark:bg-info/30 dark:text-info',
    sent: 'bg-info/15 text-info dark:bg-info/30 dark:text-info',
    delivered: 'bg-primary/15 text-accent-foreground dark:bg-primary/30 dark:text-accent-foreground',
    read: 'bg-success/15 text-success dark:bg-success/30 dark:text-success',
    failed: 'bg-destructive/15 text-destructive dark:bg-destructive/30 dark:text-destructive',
    skipped: 'bg-muted text-muted-foreground',
};

export const DAY_OPTIONS = [
    { key: 'mon', label: 'Lun' },
    { key: 'tue', label: 'Mar' },
    { key: 'wed', label: 'Mié' },
    { key: 'thu', label: 'Jue' },
    { key: 'fri', label: 'Vie' },
    { key: 'sat', label: 'Sáb' },
    { key: 'sun', label: 'Dom' },
];

export const DAY_LABEL = Object.fromEntries(DAY_OPTIONS.map(d => [d.key, d.label]));

export function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}
