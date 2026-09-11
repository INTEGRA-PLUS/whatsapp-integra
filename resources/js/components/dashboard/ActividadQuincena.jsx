import { useState } from 'react';

/**
 * Mensajes por día de las últimas dos semanas.
 *
 * Dos series —lo que entra y lo que sale— porque la pregunta que contesta esta
 * gráfica no es «cuánto se mueve» sino «estamos respondiendo». Una empresa con
 * la línea de entrantes despegada de la de salientes tiene clientes esperando,
 * y eso no se ve en ningún número suelto de la fila de arriba.
 *
 * Va en SVG a mano, sin librería de gráficas: el proyecto no tiene ninguna y
 * meter uno entero para catorce puntos engorda el paquete que descarga cada
 * cliente. El panel maestro ya dibuja las suyas así.
 *
 * Los colores son `chart-1` y `chart-2` del sistema de diseño, en ese orden.
 * No se eligen a ojo: el par se validó para daltonismo (ΔE 29 en deuteranopía,
 * 32 en visión normal) y contra las dos superficies, la clara y el navy. Aun
 * así la identidad nunca depende sólo del color — hay leyenda y el tooltip
 * nombra cada serie.
 */
export default function ActividadQuincena({ datos = [] }) {
    const [activo, setActivo] = useState(null);

    const vacia = datos.every(d => d.entrantes === 0 && d.salientes === 0);

    // El techo de la escala. El mínimo de 4 evita que un solo mensaje en toda
    // la quincena dibuje una montaña que parece un pico de actividad.
    const techo = Math.max(...datos.flatMap(d => [d.entrantes, d.salientes]), 4);

    const ancho = 720;
    const alto = 200;
    const margen = { arriba: 12, derecha: 8, abajo: 26, izquierda: 34 };
    const anchoUtil = ancho - margen.izquierda - margen.derecha;
    const altoUtil = alto - margen.arriba - margen.abajo;

    const x = i => margen.izquierda + (datos.length <= 1 ? anchoUtil / 2 : (i * anchoUtil) / (datos.length - 1));
    const y = v => margen.arriba + altoUtil - (v / techo) * altoUtil;

    const linea = clave => datos.map((d, i) => `${i === 0 ? 'M' : 'L'} ${x(i)},${y(d[clave])}`).join(' ');

    // Tres marcas en el eje: cero, la mitad y el techo. Más rayas compiten con
    // los datos y esto es una gráfica de 200px de alto.
    const marcas = [0, Math.round(techo / 2), techo];

    const alPasar = evento => {
        const caja = evento.currentTarget.getBoundingClientRect();
        const relativo = ((evento.clientX - caja.left) / caja.width) * ancho;
        const i = Math.round(((relativo - margen.izquierda) / anchoUtil) * (datos.length - 1));
        setActivo(Math.max(0, Math.min(datos.length - 1, i)));
    };

    return (
        <section className="rounded-xl border border-border bg-card p-5">
            <header className="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h2 className="text-sm font-black tracking-tight text-foreground">Actividad</h2>
                    <p className="text-xs text-muted-foreground">Mensajes por día, últimas dos semanas</p>
                </div>
                <div className="flex items-center gap-4">
                    <Serie color="var(--chart-1)" nombre="Recibidos" />
                    <Serie color="var(--chart-2)" nombre="Enviados" />
                </div>
            </header>

            {vacia ? (
                <div className="h-[200px] flex items-center justify-center text-center">
                    <p className="text-xs text-muted-foreground max-w-xs">
                        Todavía no hay mensajes en estas dos semanas. Cuando tu WhatsApp empiece a
                        moverse, aquí verás si estás respondiendo al mismo ritmo al que te escriben.
                    </p>
                </div>
            ) : (
                <div className="relative">
                    <svg
                        viewBox={`0 0 ${ancho} ${alto}`}
                        className="w-full h-[200px]"
                        onMouseMove={alPasar}
                        onMouseLeave={() => setActivo(null)}
                        role="img"
                        aria-label="Mensajes recibidos y enviados por día en las últimas dos semanas"
                    >
                        {marcas.map(m => (
                            <g key={m}>
                                <line
                                    x1={margen.izquierda} x2={ancho - margen.derecha}
                                    y1={y(m)} y2={y(m)}
                                    className="stroke-border" strokeWidth="1"
                                />
                                <text
                                    x={margen.izquierda - 8} y={y(m) + 3}
                                    textAnchor="end"
                                    className="fill-muted-foreground text-[10px]"
                                >
                                    {m}
                                </text>
                            </g>
                        ))}

                        {activo !== null && (
                            <line
                                x1={x(activo)} x2={x(activo)}
                                y1={margen.arriba} y2={margen.arriba + altoUtil}
                                className="stroke-muted-foreground/40" strokeWidth="1" strokeDasharray="3 3"
                            />
                        )}

                        <path d={linea('entrantes')} fill="none" stroke="var(--chart-1)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        <path d={linea('salientes')} fill="none" stroke="var(--chart-2)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />

                        {activo !== null && ['entrantes', 'salientes'].map((clave, n) => (
                            <circle
                                key={clave}
                                cx={x(activo)} cy={y(datos[activo][clave])} r="4"
                                fill={n === 0 ? 'var(--chart-1)' : 'var(--chart-2)'}
                                // El anillo del color de la tarjeta separa los dos puntos
                                // cuando coinciden en el mismo valor y se solapan.
                                className="stroke-card" strokeWidth="2"
                            />
                        ))}

                        {datos.map((d, i) => (
                            // Sólo un día de cada tres lleva etiqueta: catorce fechas
                            // seguidas se pisan unas a otras en pantallas estrechas.
                            i % 3 === 0 ? (
                                <text
                                    key={d.dia} x={x(i)} y={alto - 6}
                                    textAnchor="middle"
                                    className="fill-muted-foreground text-[10px]"
                                >
                                    {d.etiqueta}
                                </text>
                            ) : null
                        ))}
                    </svg>

                    {activo !== null && (
                        <div
                            className="pointer-events-none absolute top-0 rounded-lg border border-border bg-popover px-3 py-2 shadow-lg"
                            style={{
                                left: `${(x(activo) / ancho) * 100}%`,
                                transform: x(activo) > ancho / 2 ? 'translateX(-110%)' : 'translateX(10%)',
                            }}
                        >
                            <p className="text-[11px] font-bold text-popover-foreground mb-1">{datos[activo].etiqueta}</p>
                            <p className="text-[11px] text-muted-foreground">
                                <span className="inline-block size-2 rounded-full align-middle mr-1.5" style={{ background: 'var(--chart-1)' }} />
                                Recibidos: <span className="font-bold text-popover-foreground">{datos[activo].entrantes}</span>
                            </p>
                            <p className="text-[11px] text-muted-foreground">
                                <span className="inline-block size-2 rounded-full align-middle mr-1.5" style={{ background: 'var(--chart-2)' }} />
                                Enviados: <span className="font-bold text-popover-foreground">{datos[activo].salientes}</span>
                            </p>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

function Serie({ color, nombre }) {
    return (
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <span className="inline-block h-0.5 w-4 rounded-full" style={{ background: color }} />
            {nombre}
        </span>
    );
}
