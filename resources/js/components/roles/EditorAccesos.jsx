import { useState } from 'react';
import { AlertTriangle, Check, ChevronDown, SlidersHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    contarModulo,
    idsDeNivel,
    iconoDe,
    nivelDe,
    nivelesDe,
} from './permisos';

/**
 * Elegir el acceso de un módulo.
 *
 * La decisión de fondo: primero se elige un NIVEL («solo ver», «operar»,
 * «control total»), que es como la gente piensa el problema, y solo si hace
 * falta se abre el ajuste fino con las casillas sueltas. La pantalla anterior
 * empezaba por las casillas, que es el último paso, y encima las rotulaba en
 * inglés.
 */
function Modulo({ modulo, seleccionados, onCambiar, niveles }) {
    const [abierto, setAbierto] = useState(false);

    const Icono = iconoDe(modulo.icono);
    const disponibles = nivelesDe(modulo);
    const nivel = nivelDe(modulo, seleccionados);
    const marcados = contarModulo(modulo, seleccionados);
    const activo = marcados > 0;

    // Un módulo con un solo permiso (Reportes, Notificaciones) no tiene niveles
    // que comparar: es sí o no.
    const binario = modulo.permisos.length === 1;

    const aplicarNivel = (clave) => {
        const propios = modulo.permisos.map((p) => p.id);
        const nuevos = seleccionados.filter((id) => !propios.includes(id));
        onCambiar([...nuevos, ...idsDeNivel(modulo, clave)]);
    };

    const alternarPermiso = (id) => {
        onCambiar(
            seleccionados.includes(id)
                ? seleccionados.filter((x) => x !== id)
                : [...seleccionados, id],
        );
    };

    const alternarBinario = () => {
        const id = modulo.permisos[0].id;
        alternarPermiso(id);
    };

    return (
        <div
            className={cn(
                'rounded-2xl border transition-colors',
                activo ? 'border-primary/40 bg-primary/[0.03]' : 'border-border bg-card',
            )}
        >
            <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
                <div className="flex min-w-0 flex-1 items-start gap-3">
                    <div
                        className={cn(
                            'flex size-10 shrink-0 items-center justify-center rounded-xl',
                            activo ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                        )}
                    >
                        <Icono className="size-5" />
                    </div>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h4 className="font-semibold text-foreground">{modulo.nombre}</h4>
                            {modulo.delicado && (
                                <span className="inline-flex items-center gap-1 rounded-md bg-amber-500/15 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:text-amber-400">
                                    <AlertTriangle className="size-3" />
                                    Delicado
                                </span>
                            )}
                        </div>
                        <p className="mt-0.5 text-sm leading-snug text-muted-foreground">
                            {modulo.ayuda}
                        </p>
                    </div>
                </div>

                {binario ? (
                    <button
                        type="button"
                        onClick={alternarBinario}
                        aria-pressed={activo}
                        className={cn(
                            'shrink-0 rounded-xl border px-4 py-2 text-sm font-semibold transition-colors',
                            activo
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-muted text-muted-foreground hover:border-primary/40',
                        )}
                    >
                        {activo ? 'Con acceso' : 'Sin acceso'}
                    </button>
                ) : (
                    <div
                        role="radiogroup"
                        aria-label={`Nivel de acceso a ${modulo.nombre}`}
                        className="flex shrink-0 flex-wrap gap-1 rounded-xl bg-muted p-1"
                    >
                        {disponibles.map((clave) => {
                            const activoNivel = nivel === clave;
                            return (
                                <button
                                    key={clave}
                                    type="button"
                                    role="radio"
                                    aria-checked={activoNivel}
                                    title={niveles[clave].ayuda}
                                    onClick={() => aplicarNivel(clave)}
                                    className={cn(
                                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                        activoNivel
                                            ? 'bg-card text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {niveles[clave].etiqueta}
                                </button>
                            );
                        })}
                        {nivel === null && (
                            <span className="self-center rounded-lg bg-card px-3 py-1.5 text-xs font-semibold text-foreground shadow-sm">
                                Personalizado
                            </span>
                        )}
                    </div>
                )}
            </div>

            {!binario && (
                <div className="border-t border-border/60 px-5">
                    <button
                        type="button"
                        onClick={() => setAbierto(!abierto)}
                        aria-expanded={abierto}
                        className="flex w-full items-center gap-2 py-3 text-xs font-semibold text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <SlidersHorizontal className="size-3.5" />
                        Ajuste fino
                        <span className="font-normal">
                            ({marcados} de {modulo.permisos.length} permisos)
                        </span>
                        <ChevronDown
                            className={cn('ml-auto size-4 transition-transform', abierto && 'rotate-180')}
                        />
                    </button>

                    {abierto && (
                        <div className="grid gap-2 pb-5 sm:grid-cols-2">
                            {modulo.permisos.map((permiso) => {
                                const marcado = seleccionados.includes(permiso.id);
                                return (
                                    <label
                                        key={permiso.id}
                                        className={cn(
                                            'flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition-colors',
                                            marcado
                                                ? 'border-primary/40 bg-primary/5'
                                                : 'border-border bg-muted/40 hover:border-border',
                                        )}
                                    >
                                        <input
                                            type="checkbox"
                                            className="sr-only"
                                            checked={marcado}
                                            onChange={() => alternarPermiso(permiso.id)}
                                        />
                                        <span
                                            className={cn(
                                                'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md border transition-colors',
                                                marcado
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-border bg-card text-transparent',
                                            )}
                                        >
                                            <Check className="size-3.5" />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-sm font-semibold text-foreground">
                                                {permiso.etiqueta}
                                            </span>
                                            {permiso.ayuda && (
                                                <span className="block text-xs leading-snug text-muted-foreground">
                                                    {permiso.ayuda}
                                                </span>
                                            )}
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

export default function EditorAccesos({ grupos, seleccionados, onCambiar, niveles }) {
    return (
        <div className="space-y-10">
            {grupos.map((grupo) => {
                const idsGrupo = grupo.modulos.flatMap((m) => m.permisos.map((p) => p.id));
                const todos = idsGrupo.every((id) => seleccionados.includes(id));

                return (
                    <section key={grupo.clave}>
                        <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-bold text-foreground">{grupo.titulo}</h3>
                                <p className="text-sm text-muted-foreground">{grupo.ayuda}</p>
                            </div>
                            {!grupo.sobrante && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        onCambiar(
                                            todos
                                                ? seleccionados.filter((id) => !idsGrupo.includes(id))
                                                : [...new Set([...seleccionados, ...idsGrupo])],
                                        )
                                    }
                                    className="text-xs font-semibold text-primary transition-opacity hover:opacity-70"
                                >
                                    {todos ? 'Quitar todo el grupo' : 'Dar control total al grupo'}
                                </button>
                            )}
                        </div>

                        <div className="space-y-3">
                            {grupo.modulos.map((modulo) => (
                                <Modulo
                                    key={modulo.clave}
                                    modulo={modulo}
                                    seleccionados={seleccionados}
                                    onCambiar={onCambiar}
                                    niveles={niveles}
                                />
                            ))}
                        </div>
                    </section>
                );
            })}
        </div>
    );
}
