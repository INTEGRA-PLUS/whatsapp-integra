import { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    ArrowRight, Bot, CheckCircle2, Inbox, Layers, MessageSquare,
    Plug, Tag as TagIcon, UserPlus, Users,
} from 'lucide-react';

/**
 * Cómo se usa esto, dibujado.
 *
 * Un cliente que entra por primera vez no tiene un problema de funciones: tiene
 * un problema de orden. Sabe que hay etiquetas, un tablero y respuestas
 * automáticas, pero no en qué secuencia se tocan ni por qué el tablero está
 * vacío si no ha creado etiquetas antes. Eso no se arregla con una lista de
 * enlaces en el menú, que es lo único que había.
 *
 * Son dos diagramas y no uno porque son dos preguntas distintas, y mezclarlas
 * fue el primer intento fallido: «qué configuro» la hace el dueño una vez, y
 * «cómo se trabaja» la hacen sus agentes todos los días. En una sola fila de
 * ocho pasos, el agente leía cuatro que no le tocaban nunca.
 *
 * En pantalla estrecha los pasos se apilan y las flechas giran: una fila de
 * cinco tarjetas en un móvil obliga a un desplazamiento lateral que nadie hace.
 */
export default function Flujograma({ puestaEnMarcha }) {
    const [vista, setVista] = useState('marcha');

    return (
        <section className="rounded-xl border border-border bg-card p-5">
            <header className="flex flex-wrap items-start justify-between gap-3 mb-5">
                <div>
                    <h2 className="text-sm font-black tracking-tight text-foreground">Cómo funciona</h2>
                    <p className="text-xs text-muted-foreground">
                        {vista === 'marcha'
                            ? 'Lo que se configura una vez, en el orden en que conviene hacerlo'
                            : 'El camino que recorre cada conversación, todos los días'}
                    </p>
                </div>

                <div className="flex rounded-lg border border-border p-0.5" role="tablist">
                    <Pestana activa={vista === 'marcha'} onClick={() => setVista('marcha')}>
                        Puesta en marcha
                    </Pestana>
                    <Pestana activa={vista === 'dia'} onClick={() => setVista('dia')}>
                        El día a día
                    </Pestana>
                </div>
            </header>

            {vista === 'marcha'
                ? <Diagrama pasos={pasosDeMarcha(puestaEnMarcha)} />
                : <Diagrama pasos={PASOS_DEL_DIA} />}
        </section>
    );
}

function Pestana({ activa, onClick, children }) {
    return (
        <button
            type="button"
            role="tab"
            aria-selected={activa}
            onClick={onClick}
            className={[
                'px-3 py-1.5 text-xs font-bold rounded-md transition-colors',
                activa
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:text-foreground',
            ].join(' ')}
        >
            {children}
        </button>
    );
}

/**
 * La fila de pasos con sus flechas.
 *
 * El estado de cada paso se dice con icono y texto además del color: «hecho» en
 * verde y «pendiente» en gris son indistinguibles para buena parte de la gente
 * daltónica, y este diagrama no sirve de nada si no se puede leer el estado.
 */
function Diagrama({ pasos }) {
    return (
        <ol className="flex flex-col lg:flex-row lg:items-stretch gap-2">
            {pasos.map((paso, i) => (
                <li key={paso.titulo} className="flex flex-col lg:flex-row lg:items-stretch gap-2 lg:flex-1">
                    <Paso {...paso} />
                    {i < pasos.length - 1 && (
                        <div className="flex items-center justify-center shrink-0 text-muted-foreground/40">
                            <ArrowRight className="size-4 rotate-90 lg:rotate-0" aria-hidden="true" />
                        </div>
                    )}
                </li>
            ))}
        </ol>
    );
}

function Paso({ icono: Icono, titulo, detalle, estado, href }) {
    const hecho = estado === 'hecho';

    const contenido = (
        <div
            className={[
                'h-full flex flex-col gap-2 rounded-lg border p-3 transition-colors',
                hecho
                    ? 'border-success/30 bg-success/5'
                    : 'border-border bg-background',
                href ? 'hover:border-primary/50 hover:bg-primary/5' : '',
            ].join(' ')}
        >
            <div className="flex items-center gap-2">
                <span
                    className={[
                        'flex size-7 shrink-0 items-center justify-center rounded-lg',
                        hecho ? 'bg-success/15 text-success' : 'bg-muted text-muted-foreground',
                    ].join(' ')}
                >
                    {hecho ? <CheckCircle2 className="size-4" /> : <Icono className="size-4" />}
                </span>
                <p className="text-xs font-bold text-foreground leading-tight">{titulo}</p>
            </div>

            <p className="text-[11px] leading-snug text-muted-foreground">{detalle}</p>

            {estado && (
                <p className={[
                    'mt-auto text-[10px] font-bold uppercase tracking-widest',
                    hecho ? 'text-success' : 'text-muted-foreground/70',
                ].join(' ')}>
                    {hecho ? 'Listo' : 'Pendiente'}
                </p>
            )}
        </div>
    );

    return href ? <Link href={href} className="block h-full">{contenido}</Link> : contenido;
}

/**
 * Los pasos de configuración, con el estado real que trae el servidor.
 *
 * Se reutiliza lo que ya calculó el controlador en vez de volver a decidir aquí
 * qué está hecho: si la lista de abajo y el diagrama contaran los pasos por su
 * cuenta, tarde o temprano dirían cosas distintas en la misma pantalla.
 */
function pasosDeMarcha(puestaEnMarcha) {
    const iconos = {
        whatsapp: Plug,
        etiquetas: TagIcon,
        equipo: UserPlus,
        horario: MessageSquare,
        automatico: Bot,
    };

    return (puestaEnMarcha?.pasos ?? []).map(paso => ({
        icono: iconos[paso.clave] ?? CheckCircle2,
        titulo: paso.titulo,
        detalle: paso.detalle,
        estado: paso.hecho ? 'hecho' : 'pendiente',
    }));
}

// El día a día no tiene estado: no es una lista de tareas que se completa, es
// el ciclo que se repite con cada cliente que escribe.
const PASOS_DEL_DIA = [
    {
        icono: Inbox,
        titulo: 'Entra el mensaje',
        detalle: 'El cliente escribe a tu número y la conversación aparece en el chat, sin asignar a nadie.',
    },
    {
        icono: Users,
        titulo: 'Se asigna',
        detalle: 'Un agente la toma o se la asignas tú. Desde ahí se sabe quién responde y a quién preguntarle.',
    },
    {
        icono: MessageSquare,
        titulo: 'Se responde',
        detalle: 'Con respuestas rápidas para lo de siempre y macros para lo que lleva varios pasos.',
    },
    {
        icono: TagIcon,
        titulo: 'Se etiqueta',
        detalle: 'La etiqueta dice de qué va: soporte, venta, cobro. Es lo que mueve la tarjeta en el tablero.',
    },
    {
        icono: Layers,
        titulo: 'Avanza en el tablero',
        detalle: 'La conversación cambia de columna según avanza, y se ve de un vistazo qué lleva días parado.',
    },
    {
        icono: CheckCircle2,
        titulo: 'Se cierra',
        detalle: 'Queda el historial completo en la ficha del contacto, para la próxima vez que escriba.',
    },
];
