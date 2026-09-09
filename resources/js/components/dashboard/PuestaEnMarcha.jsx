import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Circle, PartyPopper } from 'lucide-react';

/**
 * Qué le falta a esta empresa para tener el CRM funcionando.
 *
 * Cada paso se comprueba contra la base de datos en cada carga, no contra una
 * casilla guardada: un cliente que borra sus etiquetas vuelve a ver ese paso
 * pendiente, porque es la verdad. Una lista de tareas que se queda marcada
 * cuando lo de debajo ya no existe miente, y encima estorba.
 *
 * Sólo se destaca el primer paso pendiente. La versión anterior de esta idea
 * ofrecía los seis botones a la vez y el cliente hacía el más fácil —invitar
 * usuarios— antes que el único que importa, que es conectar el WhatsApp: sin
 * eso lo demás no tiene nada que hacer.
 */
export default function PuestaEnMarcha({ datos, rutas }) {
    const { pasos = [], hechos = 0, total = 0, completo = false } = datos ?? {};

    const siguiente = pasos.find(p => !p.hecho);
    const porcentaje = total === 0 ? 0 : Math.round((hechos / total) * 100);

    return (
        <section className="rounded-xl border border-border bg-card p-5">
            <header className="flex items-center justify-between gap-3 mb-1">
                <h2 className="text-sm font-black tracking-tight text-foreground">Puesta en marcha</h2>
                <p className="text-xs font-bold text-muted-foreground tabular-nums">
                    {hechos} de {total}
                </p>
            </header>

            <div className="h-1.5 w-full rounded-full bg-muted overflow-hidden mb-4">
                <div
                    className="h-full rounded-full bg-primary transition-all duration-500"
                    style={{ width: `${porcentaje}%` }}
                    role="progressbar"
                    aria-valuenow={hechos}
                    aria-valuemin={0}
                    aria-valuemax={total}
                    aria-label={`${hechos} de ${total} pasos completados`}
                />
            </div>

            {completo ? (
                <div className="flex items-start gap-3 rounded-lg border border-success/30 bg-success/5 p-3">
                    <PartyPopper className="size-4 shrink-0 text-success mt-0.5" />
                    <p className="text-xs text-muted-foreground">
                        <span className="font-bold text-foreground">Todo listo.</span>{' '}
                        Tu CRM está configurado. De aquí en adelante esta pantalla te sirve para
                        vigilar que nada se quede sin responder.
                    </p>
                </div>
            ) : (
                <ul className="space-y-1">
                    {pasos.map(paso => {
                        const esSiguiente = paso.clave === siguiente?.clave;

                        return (
                            <li key={paso.clave}>
                                <div
                                    className={[
                                        'flex items-start gap-3 rounded-lg p-2.5 transition-colors',
                                        esSiguiente ? 'bg-primary/5 ring-1 ring-primary/20' : '',
                                    ].join(' ')}
                                >
                                    {paso.hecho ? (
                                        <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-success text-success-foreground">
                                            <Check className="size-3" strokeWidth={3} />
                                        </span>
                                    ) : (
                                        <Circle className="mt-0.5 size-4 shrink-0 text-muted-foreground/40" />
                                    )}

                                    <div className="min-w-0 flex-1">
                                        <p className={[
                                            'text-xs font-bold leading-tight',
                                            paso.hecho ? 'text-muted-foreground line-through' : 'text-foreground',
                                        ].join(' ')}>
                                            {paso.titulo}
                                        </p>

                                        {/* El porqué del paso sólo se enseña en el que toca hacer
                                            ahora. En los seis a la vez, el bloque se volvía un muro
                                            de texto que nadie lee. */}
                                        {esSiguiente && (
                                            <p className="mt-1 text-[11px] leading-snug text-muted-foreground">
                                                {paso.detalle}
                                            </p>
                                        )}
                                    </div>

                                    {esSiguiente && rutas?.[paso.clave] && (
                                        <Link
                                            href={rutas[paso.clave]}
                                            className="shrink-0 inline-flex items-center gap-1 rounded-lg bg-primary px-2.5 py-1.5 text-[11px] font-bold text-primary-foreground hover:opacity-90 transition-opacity"
                                        >
                                            {paso.accion}
                                            <ArrowRight className="size-3" />
                                        </Link>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
