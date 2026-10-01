import { useMemo, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, BookOpen, Clock, ListOrdered, Search } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import CabeceraModulo from '@/components/cabecera-modulo';
import { GUIAS } from './contenido';

/**
 * El índice de guías.
 *
 * El buscador mira también dentro de los pasos y no sólo el título: quien
 * busca «pdf» o «factura» está buscando la guía de plantillas aunque no lo
 * sepa.
 */
export default function GuiasIndex() {
    const [q, setQ] = useState('');

    const visibles = useMemo(() => {
        const t = q.trim().toLowerCase();
        if (!t) return GUIAS;

        return GUIAS.filter(g => [
            g.titulo, g.resumen, g.tema, g.compartir,
            ...g.pasos.map(p => `${p.titulo} ${p.donde} ${p.aviso ?? ''} ${p.consejo ?? ''}`),
        ].join(' ').toLowerCase().includes(t));
    }, [q]);

    return (
        <>
            <Head title="Guías" />

            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 sm:p-6 lg:p-8">
                <CabeceraModulo
                    icono={BookOpen}
                    titulo="Guías"
                    descripcion="Paso a paso de lo que más se pregunta: dónde se hace, cuánto tarda y qué suele salir mal."
                />

                <label className="relative block">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="search"
                        value={q}
                        onChange={e => setQ(e.target.value)}
                        placeholder="Buscar una guía: plantilla, factura, PDF…"
                        className="h-11 w-full rounded-xl border border-input bg-card pl-10 pr-3 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    />
                </label>

                {visibles.length === 0 ? (
                    <p className="rounded-xl border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                        No hay ninguna guía sobre «{q}» todavía.
                    </p>
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2">
                        {visibles.map(g => {
                            const Icono = g.icono ?? BookOpen;
                            return (
                                <li key={g.slug}>
                                    <Link
                                        href={route('guias.show', g.slug)}
                                        className="group flex h-full flex-col gap-3 rounded-2xl border bg-card p-5 transition-colors hover:border-primary/50 hover:bg-primary/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                    >
                                        <div className="flex items-start gap-3">
                                            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary ring-1 ring-primary/20">
                                                <Icono className="size-5" />
                                            </div>
                                            <div className="min-w-0">
                                                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{g.tema}</p>
                                                <h2 className="text-base font-semibold leading-snug text-foreground">{g.titulo}</h2>
                                            </div>
                                        </div>

                                        <p className="text-sm leading-relaxed text-muted-foreground">{g.resumen}</p>

                                        <div className="mt-auto flex flex-wrap items-center gap-x-4 gap-y-1.5 pt-1 text-xs text-muted-foreground">
                                            <span className="inline-flex items-center gap-1.5">
                                                <ListOrdered className="size-3.5" /> {g.pasos.length} pasos
                                            </span>
                                            <span className="inline-flex items-center gap-1.5">
                                                <Clock className="size-3.5" /> {g.tiempos.total}
                                            </span>
                                            <span className="ml-auto inline-flex items-center gap-1 font-medium text-foreground">
                                                Ver guía <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                                            </span>
                                        </div>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </>
    );
}

GuiasIndex.layout = page => <AppLayout breadcrumb={['Guías']}>{page}</AppLayout>;
