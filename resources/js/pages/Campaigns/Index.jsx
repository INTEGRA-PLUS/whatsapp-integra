import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    CalendarClock, CheckCheck, Eye, HelpCircle, Megaphone, Plus, RefreshCw, Send, Trash2,
} from 'lucide-react';
import { DAY_LABEL, STATUS_CLASS, STATUS_LABEL, formatDate } from './shared';
import CampaignHelp from './CampaignHelp';

export default function CampaignsIndex({ campaigns = [], instances = [] }) {
    const [showHelp, setShowHelp] = useState(false);

    const listas = instances.filter(i => i.ready);
    const puedeCrear = listas.length > 0;

    function handleSend(campaign) {
        if (!confirm(`¿Iniciar el envío de "${campaign.name}" a ${campaign.total_recipients} destinatarios?`)) return;
        router.post(route('campaigns.send', campaign.id));
    }

    function handleDelete(campaign) {
        if (!confirm(`¿Eliminar la campaña "${campaign.name}"?`)) return;
        router.delete(route('campaigns.destroy', campaign.id));
    }

    return (
        <>
            <Head title="Campañas" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Campañas</h1>
                        <p className="text-sm text-muted-foreground mt-1 max-w-2xl">
                            Un mismo aviso a mucha gente. Se envía con una plantilla aprobada por WhatsApp, que es lo único
                            que llega a quien no te ha escrito en las últimas 24 horas.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" onClick={() => setShowHelp(true)} className="gap-2">
                            <HelpCircle className="size-4" /> ¿Cómo funciona?
                        </Button>
                        <Link href={puedeCrear ? route('campaigns.create') : '#'}>
                            <Button className="gap-2" disabled={!puedeCrear}>
                                <Plus className="size-4" /> Nueva campaña
                            </Button>
                        </Link>
                    </div>
                </div>

                {!puedeCrear && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                        Para crear campañas necesitas una línea activa con WhatsApp Business conectado.
                    </div>
                )}

                {campaigns.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                        <Megaphone className="size-12 text-muted-foreground/40 mb-4" />
                        <p className="text-lg font-medium text-foreground">Aún no tienes campañas</p>
                        <p className="text-sm text-muted-foreground mt-1 max-w-md">
                            Una campaña son tres pasos: eliges la plantilla aprobada, eliges a quién se la mandas y la ves
                            tal y como la recibirá el cliente antes de enviarla.
                        </p>
                        <div className="flex gap-2 mt-4">
                            <Button variant="outline" onClick={() => setShowHelp(true)} className="gap-2">
                                <HelpCircle className="size-4" /> Ver cómo funciona
                            </Button>
                            {puedeCrear && (
                                <Link href={route('campaigns.create')}>
                                    <Button className="gap-2"><Plus className="size-4" /> Crear la primera</Button>
                                </Link>
                            )}
                        </div>
                    </div>
                ) : (
                    <div className="rounded-xl border bg-card overflow-hidden">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                                <tr>
                                    <th className="text-left px-4 py-3 font-medium">Campaña</th>
                                    <th className="text-left px-4 py-3 font-medium">Línea</th>
                                    <th className="text-left px-4 py-3 font-medium">Estado</th>
                                    <th className="text-left px-4 py-3 font-medium">Resultado</th>
                                    <th className="text-left px-4 py-3 font-medium">Creada</th>
                                    <th className="text-right px-4 py-3 font-medium">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {campaigns.map(c => {
                                    const entregados = c.counts.delivered + c.counts.read;
                                    const enviados = c.counts.sent + entregados;
                                    return (
                                        <tr key={c.id} className="border-t hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-foreground">{c.name}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {c.uses_template
                                                        ? `Plantilla ${c.template_name}`
                                                        : 'Texto libre · hay que rehacerla con una plantilla'}
                                                </div>
                                                {c.schedule_type === 'recurring' && (
                                                    <div className="mt-0.5 inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400">
                                                        <CalendarClock className="size-3.5" />
                                                        {(c.schedule_days ?? []).map(d => DAY_LABEL[d] ?? d).join(', ')} · {(c.schedule_time ?? '').slice(0, 5)}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">{c.instance?.name ?? '—'}</td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_CLASS[c.status] ?? 'bg-muted'}`}>
                                                    {STATUS_LABEL[c.status] ?? c.status}
                                                </span>
                                                {c.schedule_type === 'recurring' && c.next_run_at && (
                                                    <div className="mt-0.5 text-[11px] text-muted-foreground">
                                                        Próximo: {formatDate(c.next_run_at)}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                <div className="flex items-center gap-2 text-xs">
                                                    <span>{enviados}/{c.total_recipients} enviados</span>
                                                    {entregados > 0 && (
                                                        <span className="inline-flex items-center gap-0.5 text-teal-600 dark:text-teal-400">
                                                            <CheckCheck className="size-3.5" /> {entregados}
                                                        </span>
                                                    )}
                                                    {c.counts.failed > 0 && (
                                                        <span className="text-red-600 dark:text-red-400">{c.counts.failed} fallidos</span>
                                                    )}
                                                </div>
                                                <div className="mt-1 h-1.5 w-32 rounded-full bg-muted overflow-hidden">
                                                    <div
                                                        className="h-full bg-green-500"
                                                        style={{ width: `${c.total_recipients > 0 ? (enviados / c.total_recipients) * 100 : 0}%` }}
                                                    />
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground text-xs">{formatDate(c.created_at)}</td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-1">
                                                    <Link href={route('campaigns.show', c.id)}>
                                                        <Button variant="outline" size="sm" className="gap-1.5">
                                                            <Eye className="size-3.5" /> Ver
                                                        </Button>
                                                    </Link>
                                                    {c.can_launch && (
                                                        <Button variant="outline" size="sm" className="gap-1.5" onClick={() => handleSend(c)}>
                                                            {c.status === 'failed' ? <RefreshCw className="size-3.5" /> : <Send className="size-3.5" />}
                                                            {c.status === 'failed' ? 'Reintentar' : 'Enviar'}
                                                        </Button>
                                                    )}
                                                    {!['queued', 'sending'].includes(c.status) && (
                                                        <Button variant="outline" size="sm" className="text-destructive hover:bg-destructive/10" onClick={() => handleDelete(c)}>
                                                            <Trash2 className="size-3.5" />
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {showHelp && <CampaignHelp campaigns={campaigns} instances={instances} onClose={() => setShowHelp(false)} />}
        </>
    );
}

CampaignsIndex.layout = page => <AppLayout breadcrumb={['Campañas']}>{page}</AppLayout>;
