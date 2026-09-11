import { useEffect, useRef, useState } from 'react';
import { Smile } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * El selector de emojis de los campos de texto.
 *
 * Los menús de WhatsApp se leen en el teléfono, y ahí un emoji al principio de
 * cada opción es lo que separa «Consultar factura» de «Reportar una falla» de
 * un vistazo. WhatsApp no pone iconos en las opciones: el único icono posible
 * es el que escriba el admin en el título, y hasta ahora había que copiarlo de
 * otro sitio y pegarlo.
 *
 * Escrito a mano y no con una librería a propósito: los paquetes de emojis
 * traen el catálogo completo con nombres traducidos y pesan cientos de kB. Aquí
 * hacen falta los de atención al cliente de una empresa de internet, que son
 * estos y caben en un archivo.
 */
const GRUPOS = [
    {
        nombre: 'Atención',
        emojis: ['👋', '😀', '🙂', '😊', '🙏', '👍', '👌', '✅', '❌', '⚠️', '❓', '❗', '💬', '🗨️', '📢', '🔔', '⏰', '🕐', '📅', '⭐'],
    },
    {
        nombre: 'Soporte',
        emojis: ['🛠️', '🔧', '🔨', '⚙️', '🧰', '🔌', '💡', '📡', '🛰️', '📶', '🌐', '💻', '🖥️', '📱', '☎️', '📞', '🔍', '🚨', '🔥', '🧑‍🔧'],
    },
    {
        nombre: 'Facturación',
        emojis: ['📄', '🧾', '💰', '💵', '💳', '🏦', '📊', '📈', '📉', '🗓️', '✍️', '📝', '📋', '📁', '📂', '🔒', '🔓', '🎟️', '🏷️', '💸'],
    },
    {
        nombre: 'Servicios',
        emojis: ['📺', '🎬', '🎵', '🎮', '🏠', '🏢', '🏪', '🚚', '📦', '🛒', '🎁', '🤝', '👤', '👥', '🧑‍💼', '📍', '🗺️', '🚗', '⚡', '💧'],
    },
];

export default function SelectorEmoji({ onElegir, className = '', titulo = 'Insertar un emoji' }) {
    const [abierto, setAbierto] = useState(false);
    const contenedor = useRef(null);

    // Cerrar al hacer clic fuera o con Escape: si no, el panel se queda abierto
    // tapando el campo siguiente.
    useEffect(() => {
        if (!abierto) return;

        const fuera = (e) => {
            if (contenedor.current && !contenedor.current.contains(e.target)) setAbierto(false);
        };
        const tecla = (e) => { if (e.key === 'Escape') setAbierto(false); };

        document.addEventListener('mousedown', fuera);
        document.addEventListener('keydown', tecla);

        return () => {
            document.removeEventListener('mousedown', fuera);
            document.removeEventListener('keydown', tecla);
        };
    }, [abierto]);

    return (
        <div ref={contenedor} className={cn('relative', className)}>
            <button
                type="button"
                title={titulo}
                aria-label={titulo}
                aria-expanded={abierto}
                onClick={() => setAbierto(a => !a)}
                className={cn(
                    'flex size-7 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                    abierto && 'bg-muted text-foreground',
                )}
            >
                <Smile className="size-4" />
            </button>

            {abierto && (
                <div className="absolute right-0 z-50 mt-1 w-[268px] rounded-2xl border border-border bg-card p-3 shadow-xl">
                    <div className="max-h-64 space-y-3 overflow-y-auto">
                        {GRUPOS.map(grupo => (
                            <div key={grupo.nombre}>
                                <p className="mb-1.5 text-[10px] font-black uppercase tracking-widest text-muted-foreground">
                                    {grupo.nombre}
                                </p>
                                <div className="grid grid-cols-8 gap-0.5">
                                    {grupo.emojis.map(emoji => (
                                        <button
                                            key={emoji}
                                            type="button"
                                            title={emoji}
                                            onClick={() => { onElegir(emoji); setAbierto(false); }}
                                            className="flex size-7 items-center justify-center rounded-lg text-lg leading-none transition-colors hover:bg-muted"
                                        >
                                            {emoji}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                    <p className="mt-2 border-t border-border pt-2 text-[10px] text-muted-foreground">
                        Se inserta donde tengas el cursor.
                    </p>
                </div>
            )}
        </div>
    );
}
