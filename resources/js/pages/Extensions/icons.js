import { AlarmClock, Blocks, PenLine, Route } from 'lucide-react';

// El manifiesto viaja con el NOMBRE del icono, no con el componente: el catálogo
// vive en PHP y no puede mandar un componente de React. Este mapa es el único
// sitio del frontend que hay que tocar al publicar una extensión con un icono
// nuevo; cualquier nombre que no esté aquí cae en el icono genérico en vez de
// dejar la tarjeta rota.
const ICONS = {
    AlarmClock,
    PenLine,
    Route,
};

export function iconFor(name) {
    return ICONS[name] ?? Blocks;
}

// Las categorías del catálogo, con el orden en el que se muestran los filtros.
export const CATEGORIES = [
    { value: 'all', label: 'Todas' },
    { value: 'conversaciones', label: 'Conversaciones' },
    { value: 'automatizacion', label: 'Automatización' },
    { value: 'productividad', label: 'Productividad' },
];

export function categoryLabel(value) {
    return CATEGORIES.find((c) => c.value === value)?.label ?? value;
}
