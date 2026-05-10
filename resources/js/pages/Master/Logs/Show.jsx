import { useState, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Trash2, AlertTriangle, FileText, Copy, Check, Eraser } from 'lucide-react';

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
                <div className="bg-card/40 backdrop-blur-3xl px-8 py-6 sticky top-0 z-40 border-b border-border/20 shadow-sm">
                    <div className="max-w-[1700px] mx-auto flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4">
                        <div className="flex items-center gap-4">
                            <Link href={route('master.logs.index')}>
                                <Button variant="ghost" size="icon" className="size-11 rounded-xl">
                                    <ArrowLeft className="size-5" />
                                </Button>
                            </Link>
                            <div className="size-12 rounded-2xl bg-indigo-600 flex items-center justify-center text-white shadow-xl shadow-indigo-600/20">
                                <FileText className="size-6" />
                            </div>
                            <div>
                                <h1 className="text-xl font-black tracking-tight text-foreground font-mono">{log.name}</h1>
                                <p className="text-xs font-bold text-muted-foreground/80 uppercase tracking-widest mt-1">
                                    {log.size_human} · {lines.length.toLocaleString()} líneas · {log.modified_at}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <Button
                                variant="ghost"
                                onClick={handleCopy}
                                className="rounded-xl h-11 px-5 font-black uppercase tracking-widest text-[10px] gap-2"
                            >
                                {copied ? <><Check className="size-4" /> Copiado</> : <><Copy className="size-4" /> Copiar</>}
                            </Button>
                            <Button
                                onClick={() => setClearing(true)}
                                className="rounded-xl h-11 px-5 bg-amber-600 hover:bg-amber-700 text-white font-black uppercase tracking-widest text-[10px] gap-2"
                            >
                                <Eraser className="size-4" /> Vaciar
                            </Button>
                            <Button
                                onClick={() => setConfirming(true)}
                                className="rounded-xl h-11 px-5 bg-rose-600 hover:bg-rose-700 text-white font-black uppercase tracking-widest text-[10px] gap-2"
                            >
                                <Trash2 className="size-4" /> Eliminar
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="p-8 max-w-[1700px] mx-auto w-full">
                    <div className="bg-zinc-950 border border-border/40 rounded-3xl shadow-xl overflow-hidden">
                        <div className="overflow-auto max-h-[calc(100vh-220px)]">
                            {log.content && log.content.length > 0 ? (
                                <pre className="text-xs font-mono leading-relaxed text-zinc-200 p-6 whitespace-pre">
                                    {lines.map((line, idx) => (
                                        <div key={idx} className="flex hover:bg-white/[0.03] transition-colors">
                                            <span className="select-none text-zinc-600 text-right pr-4 min-w-[3.5rem] tabular-nums">{idx + 1}</span>
                                            <span className="flex-1 break-all whitespace-pre-wrap">{line || ' '}</span>
                                        </div>
                                    ))}
                                </pre>
                            ) : (
                                <div className="p-16 text-center text-zinc-500 italic text-sm">
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
                            <div className="size-14 rounded-2xl bg-rose-500/10 flex items-center justify-center text-rose-600">
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
                            <Button className="flex-1 h-12 rounded-2xl bg-rose-600 hover:bg-rose-700 text-white font-black uppercase tracking-widest text-[10px]" onClick={handleDelete}>
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
                            <div className="size-14 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-600">
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
                            <Button className="flex-1 h-12 rounded-2xl bg-amber-600 hover:bg-amber-700 text-white font-black uppercase tracking-widest text-[10px]" onClick={handleClear}>
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
