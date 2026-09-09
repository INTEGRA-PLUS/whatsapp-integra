import { AlertTriangle, Ban, Check } from 'lucide-react';
import { resumen } from './permisos';

/**
 * Lo que va a poder hacer quien tenga este rol, escrito en frases y no en
 * casillas. Es el paso que faltaba: antes se guardaba a ciegas.
 */
export default function ResumenAccesos({ grupos, seleccionados, niveles, nombre }) {
    const lineas = resumen(grupos, seleccionados, niveles);

    const sinAcceso = [];
    grupos.forEach((grupo) => {
        if (grupo.sobrante) return;
        grupo.modulos.forEach((modulo) => {
            if (!modulo.permisos.some((p) => seleccionados.includes(p.id))) {
                sinAcceso.push(modulo.nombre);
            }
        });
    });

    const delicados = lineas.filter((l) => l.delicado);

    if (lineas.length === 0) {
        return (
            <div className="rounded-2xl border border-dashed p-8 text-center">
                <Ban className="mx-auto mb-3 size-8 text-muted-foreground/40" />
                <p className="font-semibold text-foreground">Este rol no tendría acceso a nada</p>
                <p className="mt-1 text-sm text-muted-foreground">
                    Se puede guardar así, pero quien lo tenga entrará a un menú vacío. Vuelve al
                    paso anterior si no era la idea.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="rounded-2xl border bg-card p-6">
                <p className="text-sm text-muted-foreground">
                    Quien tenga el rol{' '}
                    <span className="font-bold text-foreground">{nombre || 'sin nombre'}</span> podrá
                    entrar a <span className="font-bold text-foreground">{lineas.length}</span>{' '}
                    {lineas.length === 1 ? 'módulo' : 'módulos'}:
                </p>

                <ul className="mt-4 space-y-3">
                    {lineas.map((linea) => (
                        <li key={`${linea.grupo}-${linea.modulo}`} className="flex items-start gap-3">
                            <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                <Check className="size-3.5" />
                            </span>
                            <span className="min-w-0">
                                <span className="text-sm font-semibold text-foreground">
                                    {linea.modulo}
                                </span>
                                <span className="ml-2 rounded-md bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
                                    {linea.nivel}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {linea.detalle}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>

            {delicados.length > 0 && (
                <div className="flex items-start gap-3 rounded-2xl border border-amber-500/40 bg-amber-500/[0.07] p-5">
                    <AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div className="text-sm">
                        <p className="font-semibold text-foreground">Revisa esto antes de guardar</p>
                        <p className="mt-1 leading-relaxed text-muted-foreground">
                            Le estás dando acceso a{' '}
                            <span className="font-semibold text-foreground">
                                {delicados.map((d) => d.modulo).join(', ')}
                            </span>
                            . Quien pueda editar roles puede ampliarse el acceso a sí mismo y al
                            resto del equipo.
                        </p>
                    </div>
                </div>
            )}

            {sinAcceso.length > 0 && (
                <div className="rounded-2xl border bg-muted/40 p-5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        No verá en el menú
                    </p>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                        {sinAcceso.join(' · ')}
                    </p>
                </div>
            )}
        </div>
    );
}
