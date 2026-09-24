import { useState, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import CabeceraModulo from '@/components/cabecera-modulo';
import { Trash2, AlertTriangle, FileText, Copy, Check, Eraser } from 'lucide-react';

export default function LogsShow({ log }) {
    const [confirming, setConfirming] = useState(false);
    const [clearing, setClearing] = useState(false);
    const [copied, setCopied] = useState(false);

    const lines = useMemo(() => (log.content || '').split('\n'), [log.content]);

    function handleDelete() {
        router.delete(route('master.logs.destroy', log.name), {
            onFinish: () => setConfirming(false),
        });
    }

    function handleClear() {
        router.post(route('master.logs.clear', log.name), {}, {
            onFinish: () => setClearing(false),
            preserveScroll: true,
        });
    }

    function handleCopy() {
        navigator.clipboard.writeText(log.content || '').then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    }

    return (
        <>
            <Head title={`${log.name} · Logs`} />
            <div className="flex flex-col min-h-screen bg-muted/10">
                <div className="mx-auto w-full max-w-[1700px] px-4 pt-4 sm:px-8 sm:pt-6">
                    <CabeceraModulo
                        icono={FileText}
                        titulo={<span className="font-mono break-all">{log.name}</span>}
                        descripcion={`${log.size_human} · ${lines.length.toLocaleString()} líneas · ${log.modified_at}`}
                        volver={route('master.logs.index')}
                    >
                        <Button variant="outline" size="sm" onClick={handleCopy} className="gap-2">
                            {copied ? <><Check className="size-4" /> Copiado</> : <><Copy className="size-4" /> Copiar</>}
                        </Button>
                        <Button
                            size="sm"
                            onClick={() => setClearing(true)}
                            className="bg-warning hover:bg-warning text-primary-foreground gap-2"
                        >
                            <Eraser className="size-4" /> Vaciar
                        </Button>
                        <Button
                            size="sm"
                            onClick={() => setConfirming(true)}
                            className="bg-destructive hover:bg-destructive text-white gap-2"
                        >
                            <Trash2 className="size-4" /> Eliminar
                        </Button>
                    </CabeceraModulo>
                </div>

                <div className="p-8 max-w-[1700px] mx-auto w-full">
                    <div className="bg-muted border border-border/40 rounded-3xl shadow-xl overflow-hidden">
                        <div className="overflow-auto max-h-[calc(100vh-220px)]">
                            {log.content && log.content.length > 0 ? (
                                <pre className="text-xs font-mono leading-relaxed text-muted-foreground p-6 whitespace-pre">
                                    {lines.map((line, idx) => (
                                        <div key={idx} className="flex hover:bg-white/[0.03] transition-colors">
                                            <span className="select-none text-muted-foreground text-right pr-4 min-w-[3.5rem] tabular-nums">{idx + 1}</span>
                                            <span className="flex-1 break-all whitespace-pre-wrap">{line || ' '}</span>
                                        </div>
                                    ))}
                                </pre>
                            ) : (
                                <div className="p-16 text-center text-muted-foreground italic text-sm">
                                    El archivo está vacío.
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {confirming && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-background/60 backdrop-blur-2xl p-4 animate-in fade-in duration-200" onClick={() => setConfirming(false)}>
                    <div className="w-full max-w-md rounded-[2.5rem] bg-card border border-border/40 shadow-2xl p-10 relative" onClick={e => e.stopPropagation()}>
                        <div className="flex items-center gap-4 mb-6">
                            <div className="size-14 rounded-2xl bg-destructive/10 flex items-center justify-center text-destructive">
                                <AlertTriangle className="size-7" />
                            </div>
                            <h2 className="text-xl font-black tracking-tight uppercase">Eliminar Log</h2>
                        </div>
                        <p className="text-sm text-muted-foreground mb-8">
                            ¿Eliminar el archivo <code className="font-bold text-foreground">{log.name}</code>? Esta acción no se puede deshacer.
                        </p>
                        <div className="flex gap-3">
                            <Button variant="ghost" className="flex-1 h-12 rounded-2xl font-black uppercase tracking-widest text-[10px]" onClick={() => setConfirming(false)}>
                                Cancelar
                            </Button>
                            <Button className="flex-1 h-12 rounded-2xl bg-destructive hover:bg-destructive text-white font-black uppercase tracking-widest text-[10px]" onClick={handleDelete}>
                                Eliminar
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {clearing && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-background/60 backdrop-blur-2xl p-4 animate-in fade-in duration-200" onClick={() => setClearing(false)}>
                    <div className="w-full max-w-md rounded-[2.5rem] bg-card border border-border/40 shadow-2xl p-10 relative" onClick={e => e.stopPropagation()}>
                        <div className="flex items-center gap-4 mb-6">
                            <div className="size-14 rounded-2xl bg-warning/10 flex items-center justify-center text-warning">
                                <Eraser className="size-7" />
                            </div>
                            <h2 className="text-xl font-black tracking-tight uppercase">Vaciar Log</h2>
                        </div>
                        <p className="text-sm text-muted-foreground mb-8">
                            ¿Vaciar el contenido de <code className="font-bold text-foreground">{log.name}</code>? El archivo se conservará pero quedará a 0 bytes.
                        </p>
                        <div className="flex gap-3">
                            <Button variant="ghost" className="flex-1 h-12 rounded-2xl font-black uppercase tracking-widest text-[10px]" onClick={() => setClearing(false)}>
                                Cancelar
                            </Button>
                            <Button className="flex-1 h-12 rounded-2xl bg-warning hover:bg-warning text-primary-foreground font-black uppercase tracking-widest text-[10px]" onClick={handleClear}>
                                Vaciar
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

LogsShow.layout = page => <AppLayout>{page}</AppLayout>;
