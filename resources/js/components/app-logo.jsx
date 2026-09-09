/**
 * La marca en la barra lateral.
 *
 * Antes esto era un SVG dibujado a mano que se parecía al logo pero no lo era:
 * le faltaba la burbuja de WhatsApp, los anillos no coincidían y el verde no
 * era el de la marca. Ahora usa el isotipo real, recortado del logo oficial.
 *
 * El recorte va sobre su propio fondo azul noche, así que el contenedor lleva
 * ese mismo color: sobre blanco, el cuadrado del PNG se notaría. Ese hexadecimal
 * es del archivo, no del tema, y por eso no es un token.
 *
 * El texto sí lo es. Iba en `text-gray-900` fijo y en tema oscuro quedaba negro
 * sobre el navy: el nombre de la marca desaparecía de la barra lateral
 * (9-sep-2026).
 */
export default function AppLogo() {
    return (
        <div className="flex items-center gap-3">
            <div className="flex aspect-square size-10 items-center justify-center overflow-hidden rounded-lg bg-[#0A1A2F] shadow-lg">
                <img
                    src="/isotipo.png"
                    alt=""
                    className="size-full scale-[1.18] object-contain"
                />
            </div>
            <div className="grid flex-1 text-left text-sm">
                <span className="truncate leading-tight font-bold text-sidebar-foreground">Integra CRM</span>
                <span className="truncate text-[10px] uppercase tracking-tighter text-muted-foreground font-semibold">Integra Colombia</span>
            </div>
        </div>
    );
}
