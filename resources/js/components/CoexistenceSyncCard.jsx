import { useEffect, useState } from 'react';
import { CheckCircle2, CloudDownload, Info, TriangleAlert } from 'lucide-react';

/**
 * Avance de la importación de contactos e historial de un número conectado por
 * coexistencia.
 *
 * La importación tarda minutos. Sin esto la pantalla queda muda y el cliente
 * asume que falló: en el mejor caso llama a soporte, en el peor desconecta el
 * número desde el celular y gasta el único intento que Meta le da.
 *
 * El porcentaje no es una estimación nuestra. Cada webhook de historial trae el
 * progreso y la fase que reporta Meta, y el servidor los traduce a un avance
 * continuo. Ver `CoexistenceSync::porcentajeGlobal`.
 *
 * Llega por dos caminos a propósito: por websocket cuando Reverb conecta, y por
 * consulta cada pocos segundos cuando no. Los dos entregan la MISMA forma, así
 * que aquí no hay que distinguir de dónde vino. Un cliente detrás de un proxy
 * de oficina que cierra los websockets tiene que ver la barra igual.
 */
export default function CoexistenceSyncCard({ instanceId, initial }) {
    const [sync, setSync] = useState(initial ?? null);

    // Websocket. Si Reverb no está configurado, `window.Echo` no existe y este
    // efecto no hace nada: el respaldo de abajo se encarga.
    useEffect(() => {
        if (!window.Echo || !instanceId) return;

        const canal = `instance.${instanceId}`;
        window.Echo.private(canal).listen('.coexistence.sync', (e) => setSync(e));

        return () => window.Echo.leave(canal);
    }, [instanceId]);

    // Respaldo por consulta. Se apaga en cuanto la importación termina: seguir
    // preguntando por algo que ya no cambia es ruido en el servidor.
    useEffect(() => {
        if (!instanceId || sync?.terminada) return;

        const t = setInterval(async () => {
            try {
                const r = await fetch(`/instances/${instanceId}/coexistence-sync`, {
                    headers: { Accept: 'application/json' },
                });
                if (!r.ok) return;
                const { sync: datos } = await r.json();
                if (datos) setSync(datos);
            } catch {
                // Un fallo de red puntual no debe romper la pantalla: el
                // siguiente intento llega en cinco segundos.
            }
        }, 5000);

        return () => clearInterval(t);
    }, [instanceId, sync?.terminada]);

    if (!sync) return null;

    // Terminada y sin nada que contar: la tarjeta desaparece en vez de quedarse
    // como un cartel permanente de algo que ya pasó.
    if (sync.status === 'completada' && sync.mensajes === 0 && sync.contactos === 0) {
        return null;
    }

    const fallo = sync.status === 'fallida';
    const rechazada = sync.status === 'rechazada';
    const lista = sync.status === 'completada';

    return (
        <div className={`rounded-lg border px-3 py-3 text-xs ${
            fallo ? 'border-destructive/30 bg-destructive/10'
                : rechazada ? 'border-warning/30 bg-warning/10'
                : lista ? 'border-success/30 bg-success/10'
                : 'border-info/30 bg-info/10'
        }`}>
            <div className="flex items-start gap-2">
                {fallo ? <TriangleAlert className="size-4 shrink-0 text-destructive mt-px" />
                    : rechazada ? <Info className="size-4 shrink-0 text-warning mt-px" />
                    : lista ? <CheckCircle2 className="size-4 shrink-0 text-success mt-px" />
                    : <CloudDownload className="size-4 shrink-0 text-info mt-px animate-pulse" />}

                <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-3">
                        <p className={`font-medium ${
                            fallo ? 'text-destructive'
                                : rechazada ? 'text-warning'
                                : lista ? 'text-success'
                                : 'text-info'
                        }`}>
                            {lista ? 'Historial importado' : sync.etapa}
                        </p>
                        {!lista && !fallo && !rechazada && (
                            <span className="font-mono tabular-nums text-info">
                                {sync.porcentaje}%
                            </span>
                        )}
                    </div>

                    {!lista && !fallo && !rechazada && (
                        <>
                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-info/20">
                                <div
                                    className="h-full rounded-full bg-info transition-[width] duration-700 ease-out dark:bg-info"
                                    style={{ width: `${sync.porcentaje}%` }}
                                />
                            </div>
                            <p className="mt-1.5 text-muted-foreground">
                                Puedes cerrar esta ventana, el proceso continúa.
                            </p>
                        </>
                    )}

                    {sync.error && (
                        <p className={`mt-1 ${rechazada ? 'text-warning/90 dark:text-warning/90' : 'text-destructive/90 dark:text-destructive/90'}`}>
                            {sync.error}
                        </p>
                    )}

                    {(sync.contactos > 0 || sync.mensajes > 0) && (
                        <p className="mt-1.5 text-muted-foreground tabular-nums">
                            {sync.contactos > 0 && `${sync.contactos.toLocaleString('es-CO')} contactos`}
                            {sync.contactos > 0 && sync.mensajes > 0 && ' · '}
                            {sync.mensajes > 0 && `${sync.mensajes.toLocaleString('es-CO')} mensajes`}
                            {sync.conversaciones > 0 && ` en ${sync.conversaciones.toLocaleString('es-CO')} chats`}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
