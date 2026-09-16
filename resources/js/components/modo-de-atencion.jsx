import { useEffect, useState } from 'react';
import axios from 'axios';
import { AlertTriangle, Bot, CheckCircle2, ListTree, Loader2, Sparkles, UserRound } from 'lucide-react';

/**
 * Cómo quiere atender esta empresa, en una sola decisión.
 *
 * Antes esto no se elegía: **se deducía**. Estaba repartido entre el interruptor
 * de la IA de chats, el de la de menús, las palabras clave de cada menú y el
 * tipo `welcome`, que son cuatro sitios y ninguno se llama «cómo quiero
 * atender». Nadie podía pedir «atención manual» sin saber antes que eso
 * significa apagar dos interruptores y vaciar un campo de otra pantalla.
 *
 * Cada modo dice **qué recibe el cliente**, no qué se apaga por dentro: lo que
 * el admin está eligiendo es la experiencia de sus clientes, no la
 * configuración.
 *
 * Y nada se aplica sin enseñar antes qué cambia, con los nombres de los menús
 * que se van a encender o apagar. Un botón que apaga tres menús sin decir cuáles
 * es un botón que nadie pulsa dos veces.
 */

const MODOS = [
    {
        id: 'manual',
        Icono: UserRound,
        titulo: 'Solo personas',
        resumen: 'No responde nada automático',
        detalle: 'Todo mensaje que entre queda esperando a un asesor. Ni menús ni IA. Tus menús no se borran: dejan de saltar solos.',
    },
    {
        id: 'menu',
        Icono: ListTree,
        titulo: 'Con menú',
        resumen: 'El cliente elige de una lista',
        detalle: 'Al escribir recibe tus opciones y toca la que necesita. Lo que no encaje en ninguna queda para un asesor.',
    },
    {
        id: 'ia',
        Icono: Sparkles,
        titulo: 'Con IA',
        resumen: 'Conversa desde el primer mensaje',
        detalle: 'La IA entiende lo que pide con sus propias palabras y responde. Tus menús siguen existiendo, pero solo se abren si alguien los ofrece.',
    },
    {
        id: 'menu_ia',
        Icono: Bot,
        titulo: 'Menú + IA',
        resumen: 'El menú primero, la IA para el resto',
        detalle: 'Sale el menú, y lo que ninguna opción reconozca lo atiende la IA en vez de quedarse sin respuesta. Es lo que elige la mayoría.',
    },
];

export default function ModoDeAtencion({ alCambiar }) {
    const [modo, setModo] = useState(null);
    const [cambios, setCambios] = useState({});
    const [cargando, setCargando] = useState(true);
    const [guardando, setGuardando] = useState(null);
    const [confirmando, setConfirmando] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => { cargar(); }, []);

    async function cargar() {
        try {
            const { data } = await axios.get('/api/atencion/modo');
            setModo(data.modo);
            setCambios(data.cambios ?? {});
        } catch {
            setError('No se pudo cargar cómo estás atendiendo ahora.');
        } finally {
            setCargando(false);
        }
    }

    async function aplicar(id) {
        setGuardando(id); setError('');
        try {
            const { data } = await axios.post('/api/atencion/modo', { modo: id });
            setModo(data.modo);
            setCambios(data.cambios ?? {});
            setConfirmando(null);
            alCambiar?.();
        } catch (err) {
            setError(err.response?.data?.message ?? 'No se pudo cambiar el modo.');
        } finally {
            setGuardando(null);
        }
    }

    if (cargando) {
        return (
            <div className="rounded-2xl border border-border/60 bg-card/50 px-5 py-4">
                <p className="text-xs text-muted-foreground">Cargando…</p>
            </div>
        );
    }

    return (
        <div className="rounded-2xl border border-border/60 bg-card/50 p-5 space-y-4">
            <div>
                <p className="text-sm font-semibold text-foreground">¿Cómo quieres atender?</p>
                <p className="text-xs text-muted-foreground mt-1 leading-relaxed">
                    Es lo primero que decide qué recibe un cliente cuando te escribe. Puedes cambiarlo
                    cuando quieras y nada se borra.
                </p>
            </div>

            {error && (
                <p className="flex items-start gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-[11px] text-destructive">
                    <AlertTriangle className="size-3.5 mt-0.5 shrink-0" />
                    <span>{error}</span>
                </p>
            )}

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {MODOS.map(({ id, Icono, titulo, resumen, detalle }) => {
                    const actual = modo === id;
                    const cambio = cambios[id] ?? {};
                    const bloqueado = !!cambio.bloqueado;

                    return (
                        <button
                            key={id}
                            type="button"
                            disabled={bloqueado || guardando !== null}
                            onClick={() => (actual ? null : setConfirmando(id))}
                            className={`rounded-xl border p-4 text-left transition ${
                                actual
                                    ? 'border-teal-500/60 bg-teal-500/10 ring-1 ring-teal-500/30'
                                    : bloqueado
                                        ? 'border-border/40 opacity-50 cursor-not-allowed'
                                        : 'border-border/60 hover:border-border hover:bg-muted/30'
                            }`}
                        >
                            <div className="flex items-center gap-2">
                                <Icono className={`size-4 shrink-0 ${actual ? 'text-teal-600 dark:text-teal-400' : 'text-muted-foreground'}`} />
                                <p className="text-xs font-semibold text-foreground">{titulo}</p>
                                {actual && <CheckCircle2 className="ml-auto size-3.5 shrink-0 text-teal-500" />}
                            </div>
                            <p className="text-[11px] text-muted-foreground mt-1.5">{resumen}</p>
                            <p className="text-[11px] text-muted-foreground/80 mt-2 leading-relaxed">{detalle}</p>

                            {bloqueado && (
                                <p className="mt-2 text-[11px] text-warning leading-relaxed">{cambio.bloqueado}</p>
                            )}
                        </button>
                    );
                })}
            </div>

            {/* Qué va a pasar exactamente, antes de que pase. Con los nombres:
                «se apagarán tres menús» no es información, «se apagará Menú
                principal» sí. */}
            {confirmando && <Confirmacion
                modo={MODOS.find(m => m.id === confirmando)}
                cambio={cambios[confirmando] ?? {}}
                guardando={guardando === confirmando}
                onCancelar={() => setConfirmando(null)}
                onAceptar={() => aplicar(confirmando)}
            />}
        </div>
    );
}

function Confirmacion({ modo, cambio, guardando, onCancelar, onAceptar }) {
    const encender = cambio.menus_a_encender ?? [];
    const apagar = cambio.menus_a_apagar ?? [];
    const sinCambios = encender.length === 0 && apagar.length === 0 && !cambio.ia;

    return (
        <div className="rounded-xl border border-teal-500/30 bg-teal-500/5 p-4 space-y-3">
            <p className="text-xs font-medium text-foreground">
                Vas a pasar a «{modo.titulo}». Esto es lo que cambia:
            </p>

            {sinCambios ? (
                <p className="text-[11px] text-muted-foreground">
                    Nada, en realidad: tu configuración actual ya hace eso.
                </p>
            ) : (
                <ul className="space-y-1.5 text-[11px] text-muted-foreground">
                    {cambio.ia === 'encender' && <li>· Se <strong className="text-foreground">encenderá</strong> la IA que conversa.</li>}
                    {cambio.ia === 'apagar' && <li>· Se <strong className="text-foreground">apagará</strong> la IA que conversa.</li>}
                    {encender.length > 0 && (
                        <li>· Se <strong className="text-foreground">encenderán</strong> estos menús: {encender.join(', ')}.</li>
                    )}
                    {apagar.length > 0 && (
                        <li>· Se <strong className="text-foreground">apagarán</strong> estos menús: {apagar.join(', ')}. No se borran; dejan de saltar solos.</li>
                    )}
                </ul>
            )}

            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={onAceptar}
                    disabled={guardando}
                    className="inline-flex items-center gap-1.5 rounded-xl bg-teal-600 px-3.5 py-2 text-xs font-medium text-white hover:bg-teal-500 disabled:opacity-60"
                >
                    {guardando && <Loader2 className="size-3.5 animate-spin" />}
                    Sí, cambiar
                </button>
                <button
                    type="button"
                    onClick={onCancelar}
                    disabled={guardando}
                    className="rounded-xl px-3 py-2 text-xs text-muted-foreground hover:text-foreground"
                >
                    Cancelar
                </button>
            </div>
        </div>
    );
}
