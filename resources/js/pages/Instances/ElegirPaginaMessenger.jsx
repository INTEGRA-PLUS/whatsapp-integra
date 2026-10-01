import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Check, Loader2, MessageCircle } from 'lucide-react';
import CabeceraModulo from '@/components/cabecera-modulo';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Qué página se va a atender desde el CRM.
 *
 * No se conecta sola ni cuando hay una única página: el cliente acaba de dar
 * permiso sobre todas las que administra —la del negocio, la que abrió en 2014,
 * la del primo— y conectar la que no era manda los mensajes de un negocio a la
 * bandeja de otro. Verlo antes de que pase cuesta un clic.
 *
 * Las que no se pueden conectar salen igual, en gris y con el motivo. Esconder
 * una página que el cliente sabe que tiene lo deja buscándola.
 */
export default function ElegirPaginaMessenger({ paginas = [] }) {
    const [elegida, setElegida] = useState(paginas.find(p => p.puede_mensajear)?.id ?? null);
    const [guardando, setGuardando] = useState(false);

    const conectables = paginas.filter(p => p.puede_mensajear);

    function conectar() {
        if (!elegida) return;
        setGuardando(true);
        router.post(route('messenger.guardar'), { pagina_id: elegida }, {
            onFinish: () => setGuardando(false),
        });
    }

    return (
        <>
            <Head title="Elegir página de Messenger" />

            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6 lg:p-8">
                <CabeceraModulo
                    icono={MessageCircle}
                    volver={route('instances.index')}
                    titulo="¿Qué página quieres atender desde Integra?"
                    descripcion="Los mensajes de la página que elijas entrarán a tu bandeja, junto a los de WhatsApp. Puedes conectar las demás después."
                />

                {conectables.length === 0 && (
                    <div className="flex items-start gap-2.5 rounded-xl bg-warning/10 px-4 py-3 text-[13px] text-warning">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <span>
                            En ninguna de tus páginas tienes permiso para responder mensajes. Pídele al
                            administrador de la página que te dé el rol de mensajería y vuelve a intentarlo.
                        </span>
                    </div>
                )}

                <ul className="flex flex-col gap-2.5">
                    {paginas.map(pagina => {
                        const puesta = elegida === pagina.id;

                        return (
                            <li key={pagina.id}>
                                <button
                                    type="button"
                                    disabled={!pagina.puede_mensajear}
                                    onClick={() => setElegida(pagina.id)}
                                    className={cn(
                                        'flex w-full items-center gap-3.5 rounded-xl border px-4 py-3.5 text-left transition-colors',
                                        !pagina.puede_mensajear
                                            ? 'cursor-not-allowed border-border/60 opacity-60'
                                            : puesta
                                                ? 'border-primary/50 bg-primary/10'
                                                : 'border-border hover:bg-muted',
                                    )}
                                >
                                    {pagina.foto
                                        ? <img src={pagina.foto} alt="" className="size-11 shrink-0 rounded-full object-cover" />
                                        : <div className="size-11 shrink-0 rounded-full bg-muted" />}

                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-bold text-foreground">{pagina.nombre}</p>
                                        <p className="truncate font-mono text-[11px] text-muted-foreground">{pagina.id}</p>
                                        {!pagina.puede_mensajear && (
                                            <p className="mt-1 text-[11px] text-warning">
                                                No tienes permiso para responder mensajes en esta página
                                            </p>
                                        )}
                                    </div>

                                    <span className={cn(
                                        'flex size-5 shrink-0 items-center justify-center rounded-full border',
                                        puesta ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40',
                                    )}>
                                        {puesta && <Check className="size-3" strokeWidth={3} />}
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>

                <div className="flex items-center gap-3">
                    <Button onClick={conectar} disabled={!elegida || guardando}>
                        {guardando
                            ? <><Loader2 className="mr-1.5 size-4 animate-spin" /> Conectando…</>
                            : 'Conectar esta página'}
                    </Button>
                    <Button variant="ghost" onClick={() => router.visit(route('instances.index'))}>
                        Ahora no
                    </Button>
                </div>
            </div>
        </>
    );
}

ElegirPaginaMessenger.layout = page => <AppLayout children={page} />;
