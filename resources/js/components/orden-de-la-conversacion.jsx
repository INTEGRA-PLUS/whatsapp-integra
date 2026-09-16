import { useState } from 'react';
import { AlertTriangle, ChevronDown, ChevronRight } from 'lucide-react';

/**
 * Qué pasa, en orden, cuando un cliente escribe.
 *
 * Existe porque la pregunta «¿lo primero que sale es un menú o la IA?» no tenía
 * respuesta en ninguna pantalla, y la respuesta importa: **manda el menú y la IA
 * es el último recurso**. Quien no lo sabe configura la IA, prueba escribiendo
 * «hola», recibe un menú y concluye que la IA no funciona.
 *
 * Lo que enseña no es un diagrama genérico: cada paso viene marcado con si está
 * **activo en esta empresa**. Un diagrama que describe una configuración que no
 * es la tuya es peor que no tener ninguno, porque te hace buscar el fallo donde
 * no está.
 *
 * Se pinta en las dos pantallas que participan —«Menús de WhatsApp» y «IA que
 * responde»— porque la duda aparece en las dos, y mandar a la otra pantalla a
 * leerlo es como no contarlo.
 */
export default function OrdenDeLaConversacion({
    hayMenus = false,
    hayDisparadores = false,
    saludaConMenu = false,
    iaMenus = false,
    iaChat = false,
    horarios = false,
    respuestaAutomatica = false,
    abiertoPorDefecto = false,
}) {
    const [abierto, setAbierto] = useState(abiertoPorDefecto);

    const pasos = [
        {
            titulo: 'Si ya hay un asesor en el chat, el bot se calla',
            detalle: 'Ni menús ni IA. Nada peor que un bot interrumpiendo una conversación que ya está atendiendo alguien. Lo mismo si el chat está cerrado.',
            activo: true,
            siempre: true,
        },
        {
            titulo: 'Fuera de horario, responde el horario',
            detalle: horarios
                ? 'Tienes horarios configurados: fuera de ellos contesta ese mensaje y nada más se ejecuta.'
                : 'No tienes horarios configurados, así que este paso no se aplica y se atiende a cualquier hora.',
            activo: horarios,
        },
        {
            titulo: 'Si está respondiendo a un menú que le mandamos, se ejecuta esa opción',
            detalle: 'Da igual que toque el botón o que escriba «1»: mientras el menú siga en pie, se interpreta como una elección.',
            activo: hayMenus,
        },
        {
            titulo: 'Si el bot le había preguntado algo, la respuesta vuelve a quien preguntó',
            detalle: 'Quien contesta «desde ayer» a nuestra pregunta está respondiendo, no pidiendo un menú. Va antes que las palabras clave a propósito: reenviarle el menú aquí perdería lo que escribió.',
            activo: hayMenus || iaChat || iaMenus,
        },
        {
            titulo: 'Si es su PRIMER mensaje y tienes menú de bienvenida, sale ese menú',
            detalle: saludaConMenu
                ? 'Tienes un menú de bienvenida activo. No necesita ninguna palabra clave: salta en el primer mensaje escriba el cliente lo que escriba. Es la razón más común de que alguien encienda la IA, mande «hola» y reciba un menú.'
                : 'No tienes ningún menú de bienvenida, así que el primer mensaje sigue bajando por esta lista como cualquier otro.',
            activo: saludaConMenu,
            clave: true,
        },
        {
            titulo: 'Si escribe una palabra clave, sale el menú que la tenga',
            detalle: hayDisparadores
                ? 'Tus menús tienen palabras clave como «menu», «factura» o «pagar». Si el mensaje las contiene, responde el menú y no la IA.'
                : 'Ninguno de tus menús tiene palabras clave, así que hoy este paso nunca se dispara.',
            activo: hayDisparadores,
            clave: true,
        },
        {
            titulo: 'Nadie lo reconoció: entra la IA',
            detalle: iaChat || iaMenus
                ? 'Es el caso más común y para el que está la IA: «no me funciona el internet desde ayer» no usa ninguna palabra clave, así que ningún menú se dispara.'
                : 'No tienes IA encendida, así que aquí responde la respuesta automática si la tienes, o el mensaje queda para un asesor.',
            activo: iaChat || iaMenus,
            clave: true,
        },
        {
            titulo: 'Y si la IA tampoco puede, pasa a una persona',
            detalle: 'Con un resumen de lo que el cliente pedía. A quién le llega se elige en «IA que responde».',
            activo: iaChat || iaMenus,
        },
    ];

    // El malentendido más caro, y el que motivó todo esto.
    const algunMenuSalta = hayDisparadores || saludaConMenu;
    const laIaNuncaLlega = algunMenuSalta && !iaChat && !iaMenus;
    const menuSiempreDelante = algunMenuSalta && (iaChat || iaMenus);

    return (
        <div className="rounded-2xl border border-border/60 bg-card/50 overflow-hidden">
            <button
                type="button"
                onClick={() => setAbierto(v => !v)}
                className="flex w-full items-center justify-between gap-3 px-5 py-4 text-left hover:bg-muted/30"
            >
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-foreground">Qué pasa cuando alguien te escribe</p>
                    <p className="text-xs text-muted-foreground mt-0.5">
                        {menuSiempreDelante
                            ? (saludaConMenu
                                ? 'Hoy: al primer mensaje le sale tu menú de bienvenida; la IA entra sólo después, con lo que ningún menú reconozca.'
                                : 'Hoy: primero tus menús, y la IA sólo si ninguno reconoce el mensaje.')
                            : algunMenuSalta
                                ? 'Hoy: responden tus menús.'
                                : (iaChat || iaMenus)
                                    ? 'Hoy: responde la IA desde el primer mensaje, porque no tienes ningún menú que salte solo.'
                                    : 'Hoy no responde ni un menú ni la IA.'}
                    </p>
                </div>
                {abierto ? <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
                    : <ChevronRight className="size-4 shrink-0 text-muted-foreground" />}
            </button>

            {abierto && (
                <div className="border-t border-border/60 px-5 py-4 space-y-3">
                    {laIaNuncaLlega && (
                        <p className="flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-[11px] text-warning">
                            <AlertTriangle className="size-3.5 mt-0.5 shrink-0" />
                            <span>
                                Tus menús saltan pero no tienes IA encendida: a lo que no reconozca
                                ninguno le responderá lo automático, o nadie.
                            </span>
                        </p>
                    )}

                    <ol className="space-y-0">
                        {pasos.map((paso, i) => (
                            <li key={i} className="flex gap-3">
                                {/* La línea que une los pasos: es lo que hace que
                                    se lea como una secuencia y no como una lista
                                    de cosas sueltas. */}
                                <div className="flex flex-col items-center">
                                    <span className={`flex size-6 shrink-0 items-center justify-center rounded-full text-[11px] font-medium ${
                                        paso.activo
                                            ? 'bg-teal-500/15 text-teal-700 dark:text-teal-400'
                                            : 'bg-muted text-muted-foreground/60'
                                    }`}>
                                        {i + 1}
                                    </span>
                                    {i < pasos.length - 1 && (
                                        <span className="w-px flex-1 bg-border/60" aria-hidden="true" />
                                    )}
                                </div>

                                <div className={`min-w-0 pb-4 ${paso.activo ? '' : 'opacity-55'}`}>
                                    <p className={`text-xs ${paso.clave ? 'font-semibold' : 'font-medium'} text-foreground`}>
                                        {paso.titulo}
                                        {!paso.activo && !paso.siempre && (
                                            <span className="ml-2 rounded bg-muted px-1.5 py-0.5 text-[10px] font-normal text-muted-foreground">
                                                no aplica hoy
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-[11px] text-muted-foreground mt-1 leading-relaxed">{paso.detalle}</p>
                                </div>
                            </li>
                        ))}
                    </ol>

                    <p className="text-[11px] text-muted-foreground/80 leading-relaxed border-t border-border/60 pt-3">
                        En resumen: <strong className="text-foreground">mandan tus menús y la IA es el último recurso</strong>.
                        Si quieres que la IA atienda desde el primer mensaje, apaga el menú de bienvenida y quítale
                        las palabras clave a los demás: así siguen existiendo para cuando la IA los ofrezca, pero no
                        se adelantan.
                    </p>
                </div>
            )}
        </div>
    );
}
