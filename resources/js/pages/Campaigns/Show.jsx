import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Send, RefreshCw, CheckCircle2, XCircle, Clock, CalendarClock } from 'lucide-react';

const DAY_LABEL = { mon: 'Lun', tue: 'Mar', wed: 'Mié', thu: 'Jue', fri: 'Vie', sat: 'Sáb', sun: 'Dom' };

const STATUS_LABEL = {
    draft: 'Borrador',
    queued: 'En cola',
    sending: 'Enviando',
    completed: 'Completada',
    cancelled: 'Cancelada',
    failed: 'Fallida',
};

const STATUS_CLASS = {
    draft: 'bg-muted text-muted-foreground',
    queued: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    sending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    completed: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
    cancelled: 'bg-muted text-muted-foreground',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
};

export default function CampaignsShow({ campaign, recipients }) {
    const isRecurring = campaign.schedule_type === 'recurring';
    const launchable = !isRecurring && (campaign.status === 'draft' || campaign.status === 'failed');

    function handleSend() {
        if (!confirm(`Iniciar el envío a ${campaign.total_recipients} destinatarios?`)) return;
        router.post(route('campaigns.send', campaign.id));
    }

    return (
        <>
            <Head title={`Campaña: ${campaign.name}`} />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center gap-3">
                    <Link href={route('campaigns.index')}>
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <ArrowLeft className="size-3.5" /> Volver
                        </Button>
                    </Link>
                    <div className="flex-1">
                        <h1 className="text-2xl font-semibold text-foreground">{campaign.name}</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Instancia: {campaign.instance?.name ?? '—'}
                            {campaign.instance?.display_phone_number ? ` · ${campaign.instance.display_phone_number}` : ''}
                        </p>
                    </div>
                    {launchable && (
                        <Button onClick={handleSend} className="gap-2">
                            {campaign.status === 'failed' ? <RefreshCw className="size-4" /> : <Send className="size-4" />}
                            {campaign.status === 'failed' ? 'Reintentar fallidos' : 'Iniciar envío'}
                        </Button>
                    )}
                </div>

                <div className="grid gap-4 sm:grid-cols-4">
                    <Stat label="Estado">
                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_CLASS[campaign.status] ?? 'bg-muted'}`}>
                            {STATUS_LABEL[campaign.status] ?? campaign.status}
                        </span>
                    </Stat>
                    <Stat label="Total" value={campaign.total_recipients} />
                    <Stat label="Enviados" value={campaign.sent_count} valueClass="text-green-600 dark:text-green-400" />
                    <Stat label="Fallidos" value={campaign.failed_count} valueClass={campaign.failed_count > 0 ? 'text-red-600 dark:text-red-400' : ''} />
                </div>

                {isRecurring && (
                    <div className="rounded-xl border bg-card p-5">
                        <p className="text-xs font-medium text-muted-foreground uppercase mb-2 flex items-center gap-1.5">
                            <CalendarClock className="size-3.5" /> Programación
                        </p>
                        <div className="text-sm text-foreground space-y-1">
                            <div>
                                Días:{' '}
                                <span className="font-medium">
                                    {(campaign.schedule_days ?? []).map(d => DAY_LABEL[d] ?? d).join(', ') || '—'}
                                </span>
                            </div>
                            <div>Hora: <span className="font-medium">{(campaign.schedule_time ?? '').slice(0, 5) || '—'}</span></div>
                            <div className="text-muted-foreground text-xs">
                                Próximo envío: {campaign.next_run_at ? new Date(campaign.next_run_at).toLocaleString() : '—'}
                                {campaign.last_run_at && (
                                    <> · Último: {new Date(campaign.last_run_at).toLocaleString()}</>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                <div className="rounded-xl border bg-card p-5">
                    <p className="text-xs font-medium text-muted-foreground uppercase mb-2">Mensaje</p>
                    <p className="text-sm whitespace-pre-wrap text-foreground">{campaign.message}</p>
                </div>

                <div className="rounded-xl border bg-card overflow-hidden">
                    <div className="px-4 py-3 border-b bg-muted/30 text-sm font-medium">Destinatarios</div>
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                            <tr>
                                <th className="text-left px-4 py-2 font-medium">Teléfono</th>
                                <th className="text-left px-4 py-2 font-medium">Nombre</th>
                                <th className="text-left px-4 py-2 font-medium">Estado</th>
                                <th className="text-left px-4 py-2 font-medium">Enviado</th>
                                <th className="text-left px-4 py-2 font-medium">Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recipients.map(r => (
                                <tr key={r.id} className="border-t">
                                    <td className="px-4 py-2 font-mono text-foreground">{r.phone_number}</td>
                                    <td className="px-4 py-2 text-muted-foreground">{r.name ?? '—'}</td>
                                    <td className="px-4 py-2">
                                        <RecipientStatus status={r.status} />
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground text-xs">
                                        {r.sent_at ? new Date(r.sent_at).toLocaleString() : '—'}
                                    </td>
                                    <td className="px-4 py-2 text-xs text-muted-foreground max-w-md truncate" title={r.error_message ?? r.wamid ?? ''}>
                                        {r.error_message ?? r.wamid ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function Stat({ label, value, valueClass = '', children }) {
    return (
        <div className="rounded-xl border bg-card p-4">
            <p className="text-xs font-medium text-muted-foreground uppercase">{label}</p>
            {children ?? <p className={`text-2xl font-semibold mt-1 ${valueClass}`}>{value}</p>}
        </div>
    );
}

function RecipientStatus({ status }) {
    const map = {
        pending: { icon: Clock, label: 'Pendiente', cls: 'text-muted-foreground' },
        sent: { icon: CheckCircle2, label: 'Enviado', cls: 'text-green-600 dark:text-green-400' },
        failed: { icon: XCircle, label: 'Fallido', cls: 'text-red-600 dark:text-red-400' },
    };
    const s = map[status] ?? map.pending;
    const Icon = s.icon;
    return (
        <span className={`inline-flex items-center gap-1.5 ${s.cls}`}>
            <Icon className="size-3.5" /> {s.label}
        </span>
    );
}

CampaignsShow.layout = page => <AppLayout breadcrumb={['Campañas', 'Detalle']}>{page}</AppLayout>;
