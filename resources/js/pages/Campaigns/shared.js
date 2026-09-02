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
    queued: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    sending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    paused: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    completed: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
    cancelled: 'bg-muted text-muted-foreground',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
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
    sending: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    sent: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    delivered: 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300',
    read: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
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
