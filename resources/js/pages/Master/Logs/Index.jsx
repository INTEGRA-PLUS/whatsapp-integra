import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { FileText, Eye, Trash2, RefreshCw, AlertTriangle, Eraser } from 'lucide-react';

export default function LogsIndex({ logs }) {
    const [confirming, setConfirming] = useState(null);
    const [clearing, setClearing] = useState(null);

    function handleDelete(file) {
        router.delete(route('master.logs.destroy', file), {
            onFinish: () => setConfirming(null),
            preserveScroll: true,
        });
    }

    function handleClear(file) {
        router.post(route('master.logs.clear', file), {}, {
            onFinish: () => setClearing(null),
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Logs · Master" />
            <div className="flex flex-col min-h-screen bg-muted/10">
                <div className="bg-card/40 backdrop-blur-3xl px-8 py-8 sticky top-0 z-40 border-b border-border/20 shadow-sm">
                    <div className="max-w-[1700px] mx-auto flex items-center justify-between gap-6">
                        <div className="flex items-center gap-6">
                            <div className="size-14 rounded-2xl bg-indigo-600 flex items-center justify-center text-white shadow-2xl shadow-indigo-600/20">
                                <FileText className="size-7" />
                            </div>
                            <div>
                                <h1 className="text-2xl font-black tracking-tight text-foreground uppercase flex items-center gap-3">
                                    Logs del Sistema
                                    <span className="text-[10px] font-black bg-indigo-500/10 text-indigo-600 px-2 py-0.5 rounded-full border border-indigo-500/20 tracking-widest hidden sm:inline-block">MASTER</span>
                                </h1>
                                <p className="text-sm font-medium text-muted-foreground mt-1">
                                    {logs.length} {logs.length === 1 ? 'archivo' : 'archivos'} de log en <code className="text-xs">storage/logs</code>
                                </p>
                            </div>
                        </div>
                        <Button
                            onClick={() => router.reload({ only: ['logs'] })}
                            variant="ghost"
                            className="rounded-xl h-11 px-5 font-black uppercase tracking-widest text-[10px] gap-2"
                        >
                            <RefreshCw className="size-4" /> Refrescar
                        </Button>
                    </div>
                </div>

                <div className="p-8 max-w-[1700px] mx-auto w-full">
                    <div className="bg-card border border-border/40 rounded-[2.5rem] shadow-xl overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead>
                                    <tr className="bg-muted/30 border-b border-border/40">
                                        <th className="px-10 py-6 text-left text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em]">Archivo</th>
                                        <th className="px-10 py-6 text-left text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em] hidden md:table-cell">Tamaño</th>
                                        <th className="px-10 py-6 text-left text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em] hidden md:table-cell">Modificado</th>
                                        <th className="px-10 py-6 text-right text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em]">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/30">
                                    {logs.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="px-10 py-16 text-center text-sm text-muted-foreground italic">
                                                No se encontraron archivos de log.
                                            </td>
                                        </tr>
                                    )}
                                    {logs.map((log) => (
                                        <tr key={log.name} className="hover:bg-indigo-500/[0.02] transition-colors group">
                                            <td className="px-10 py-6">
                                                <div className="flex items-center gap-4">
                                                    <div className="size-12 rounded-2xl bg-indigo-500/5 border border-indigo-500/20 flex items-center justify-center text-indigo-700 group-hover:bg-indigo-600 group-hover:text-white transition-all">
                                                        <FileText className="size-5" />
                                                    </div>
                                                    <div>
                                                        <p className="font-bold text-base text-foreground group-hover:text-indigo-600 transition-colors font-mono">{log.name}</p>
                                                        <p className="text-[10px] font-bold text-muted-foreground/60 uppercase tracking-widest md:hidden mt-1">
                                                            {log.size_human} · {log.modified_at}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-10 py-6 hidden md:table-cell">
                                                <span className="text-sm font-black text-foreground font-mono">{log.size_human}</span>
                                            </td>
                                            <td className="px-10 py-6 hidden md:table-cell">
                                                <span className="text-sm font-bold text-muted-foreground font-mono">{log.modified_at}</span>
                                            </td>
                                            <td className="px-10 py-6">
                                                <div className="flex items-center justify-end gap-3">
                                                    <Link href={route('master.logs.show', log.name)}>
                                                        <Button variant="ghost" size="sm" className="rounded-xl h-10 px-4 gap-2 font-black uppercase tracking-widest text-[10px] hover:bg-indigo-600 hover:text-white transition-all">
                                                            <Eye className="size-4" /> Ver
                                                        </Button>
                                                    </Link>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => setClearing(log.name)}
                                                        className="rounded-xl h-10 px-4 gap-2 font-black uppercase tracking-widest text-[10px] text-amber-600 hover:bg-amber-600 hover:text-white transition-all"
                                                    >
                                                        <Eraser className="size-4" /> Vaciar
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => setConfirming(log.name)}
                                                        className="rounded-xl h-10 px-4 gap-2 font-black uppercase tracking-widest text-[10px] text-rose-600 hover:bg-rose-600 hover:text-white transition-all"
                                                    >
                                                        <Trash2 className="size-4" /> Eliminar
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {confirming && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-background/60 backdrop-blur-2xl p-4 animate-in fade-in duration-200" onClick={() => setConfirming(null)}>
                    <div className="w-full max-w-md rounded-[2.5rem] bg-card border border-border/40 shadow-2xl p-10 relative" onClick={e => e.stopPropagation()}>
                        <div className="flex items-center gap-4 mb-6">
                            <div className="size-14 rounded-2xl bg-rose-500/10 flex items-center justify-center text-rose-600">
                                <AlertTriangle className="size-7" />
                            </div>
                            <h2 className="text-xl font-black tracking-tight uppercase">Eliminar Log</h2>
                        </div>
                        <p className="text-sm text-muted-foreground mb-8">
                            ¿Eliminar el archivo <code className="font-bold text-foreground">{confirming}</code>? Esta acción no se puede deshacer.
                        </p>
                        <div className="flex gap-3">
                            <Button variant="ghost" className="flex-1 h-12 rounded-2xl font-black uppercase tracking-widest text-[10px]" onClick={() => setConfirming(null)}>
                                Cancelar
                            </Button>
                            <Button className="flex-1 h-12 rounded-2xl bg-rose-600 hover:bg-rose-700 text-white font-black uppercase tracking-widest text-[10px]" onClick={() => handleDelete(confirming)}>
                                Eliminar
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {clearing && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-background/60 backdrop-blur-2xl p-4 animate-in fade-in duration-200" onClick={() => setClearing(null)}>
                    <div className="w-full max-w-md rounded-[2.5rem] bg-card border border-border/40 shadow-2xl p-10 relative" onClick={e => e.stopPropagation()}>
                        <div className="flex items-center gap-4 mb-6">
                            <div className="size-14 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-600">
                                <Eraser className="size-7" />
                            </div>
                            <h2 className="text-xl font-black tracking-tight uppercase">Vaciar Log</h2>
                        </div>
                        <p className="text-sm text-muted-foreground mb-8">
                            ¿Vaciar el contenido de <code className="font-bold text-foreground">{clearing}</code>? El archivo se conservará pero quedará a 0 bytes.
                        </p>
                        <div className="flex gap-3">
                            <Button variant="ghost" className="flex-1 h-12 rounded-2xl font-black uppercase tracking-widest text-[10px]" onClick={() => setClearing(null)}>
                                Cancelar
                            </Button>
                            <Button className="flex-1 h-12 rounded-2xl bg-amber-600 hover:bg-amber-700 text-white font-black uppercase tracking-widest text-[10px]" onClick={() => handleClear(clearing)}>
                                Vaciar
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

LogsIndex.layout = page => <AppLayout>{page}</AppLayout>;
