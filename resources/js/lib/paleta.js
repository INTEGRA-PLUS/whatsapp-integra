/**
 * Los seis tonos con los que se distinguen entre sí cosas que son del mismo
 * tipo: las etapas de un tablero, las tarjetas de rol.
 *
 * El color sale de la **posición**, no de la configuración. En el tablero,
 * `kanban_columns` guarda un color por columna desde el principio y de las 123
 * de la flota 106 tenían el gris por defecto (9-sep-2026): nadie configura eso,
 * y no se le puede pedir a un cliente que coloree 43 columnas a mano.
 *
 * Las clases van escritas enteras porque Tailwind lee el código fuente: una
 * clase construida al vuelo (`bg-etapa-${n}`) no llega al CSS compilado.
 */
export const PALETA = [
    { punto: 'bg-etapa-1', texto: 'text-etapa-1', borde: 'border-etapa-1/25', barra: 'bg-etapa-1', tenue: 'bg-etapa-1/[0.07]', encima: 'bg-etapa-1/[0.06] ring-etapa-1/30' },
    { punto: 'bg-etapa-2', texto: 'text-etapa-2', borde: 'border-etapa-2/25', barra: 'bg-etapa-2', tenue: 'bg-etapa-2/[0.07]', encima: 'bg-etapa-2/[0.06] ring-etapa-2/30' },
    { punto: 'bg-etapa-3', texto: 'text-etapa-3', borde: 'border-etapa-3/25', barra: 'bg-etapa-3', tenue: 'bg-etapa-3/[0.07]', encima: 'bg-etapa-3/[0.06] ring-etapa-3/30' },
    { punto: 'bg-etapa-4', texto: 'text-etapa-4', borde: 'border-etapa-4/25', barra: 'bg-etapa-4', tenue: 'bg-etapa-4/[0.07]', encima: 'bg-etapa-4/[0.06] ring-etapa-4/30' },
    { punto: 'bg-etapa-5', texto: 'text-etapa-5', borde: 'border-etapa-5/25', barra: 'bg-etapa-5', tenue: 'bg-etapa-5/[0.07]', encima: 'bg-etapa-5/[0.06] ring-etapa-5/30' },
    { punto: 'bg-etapa-6', texto: 'text-etapa-6', borde: 'border-etapa-6/25', barra: 'bg-etapa-6', tenue: 'bg-etapa-6/[0.07]', encima: 'bg-etapa-6/[0.06] ring-etapa-6/30' },
];

export const colorPorIndice = (indice) => PALETA[indice % PALETA.length];
