import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    ChevronLeft, Download, Loader2, MessageSquare, Pause, Play, RefreshCw, Send, XCircle,
} from 'lucide-react';
import { WhatsAppPreview } from '@/pages/Templates/preview';
import {
    RECIPIENT_CLASS, RECIPIENT_LABEL, STATUS_CLASS, STATUS_LABEL, formatDate,
} from './shared';

const FILTROS = [
    { key: 'all', label: 'Todos' },
    { key: 'delivered', label: 'Entregados' },
    { key: 'read', label: 'Leídos' },
    { key: 'sent', label: 'Enviados' },
    { key: 'failed', label: 'Fallidos' },
    { key: 'pending', label: 'Pendientes' },
    { key: 'skipped', label: 'Omitidos' },
];

export default function CampaignsShow({ campaign: campaignInicial, recipients: recipientsIniciales }) {
    const [campaign, setCampaign] = useState(campaignInicial);
    const [recipients, setRecipients] = useState(recipientsIniciales);
    const [filtro, setFiltro] = useState('all');

    const enCurso = ['queued', 'sending'].includes(campaign.status);

    // Mientras envía, el detalle se refresca solo: una campaña larga se mira
    // desde esta pantalla y recargar a mano para ver si avanza es absurdo.
    useEffect(() => {
        if (!enCurso) return;
        const id = setInterval(() => {
            axios.get(route('campaigns.progress', campaign.id)).then(res => {
                setCampaign(res.data.campaign);
                setRecipients(res.data.recipients);
            });
        }, 4000);
        return () => clearInterval(id);
    }, [enCurso, campaign.id]);

    const c = campaign.counts;
    const entregados = c.delivered + c.read;
    const enviados = c.sent + entregados;
    const resueltos = enviados + c.failed + c.skipped;
    const progreso = campaign.total_recipients > 0 ? (resueltos / campaign.total_recipients) * 100 : 0;

    const filtrados = useMemo(() => {
        if (filtro === 'all') return recipients;
        if (filtro === 'pending') return recipients.filter(r => ['pending', 'sending'].includes(r.status));
        return recipients.filter(r => r.status === filtro);
    }, [recipients, filtro]);

    const modelo = useMemo(() => modeloDePlantilla(campaign), [campaign]);

    function accion(nombre, confirmacion) {
        if (confirmacion && !confirm(confirmacion)) return;
        router.post(route(`campaigns.${nombre}`, campaign.id), {}, { preserveScroll: true });
    }

    return (
        <>
            <Head title={campaign.name} />

            <div className="flex flex-col gap-6 p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3 min-w-0">
                        <Link href={route('campaigns.index')} className="mt-1 text-muted-foreground hover:text-foreground">
                            <ChevronLeft className="size-5" />
                        </Link>
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <h1 className="text-2xl font-semibold text-foreground truncate">{campaign.name}</h1>
                                <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_CLASS[campaign.status] ?? 'bg-muted'}`}>
                                    {STATUS_LABEL[campaign.status] ?? campaign.status}
                                </span>
                                {enCurso && <Loader2 className="size-4 animate-spin text-muted-foreground" />}
                            </div>
                            <p className="text-sm text-muted-foreground mt-0.5">
                                {campaign.uses_template
                                    ? <>Plantilla <span className="font-medium text-foreground">{campaign.template_name}</span> ({campaign.template_language})</>
                                    : 'Campaña de texto libre: WhatsApp no la entrega fuera de la ventana de 24 horas.'}
                                {campaign.instance ? ` · ${campaign.instance.name}` : ''}
                                {campaign.created_by ? ` · creada por ${campaign.created_by}` : ''}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {campaign.can_launch && (
                            <Button onClick={() => accion('send', `¿Enviar a ${campaign.total_recipients} destinatarios?`)} className="gap-2">
                                <Send className="size-4" /> Enviar ahora
                            </Button>
                        )}
                        {enCurso && (
                            <Button variant="outline" onClick={() => accion('pause')} className="gap-2">
                                <Pause className="size-4" /> Pausar
                            </Button>
                        )}
                        {campaign.status === 'paused' && (
                            <Button onClick={() => accion('resume')} className="gap-2">
                                <Play className="size-4" /> Reanudar
                            </Button>
                        )}
                        {(enCurso || campaign.status === 'paused') && (
                            <Button variant="outline" onClick={() => accion('cancel', '¿Cancelar el envío? Los que faltan no se enviarán.')} className="gap-2 text-destructive">
                                <XCircle className="size-4" /> Cancelar
                            </Button>
                        )}
                        {c.failed > 0 && !enCurso && (
                            <Button variant="outline" onClick={() => accion('retry-failed', `¿Reintentar ${c.failed} envíos fallidos?`)} className="gap-2">
                                <RefreshCw className="size-4" /> Reintentar fallidos
                            </Button>
                        )}
                        <a href={route('campaigns.export', campaign.id)}>
                            <Button variant="outline" className="gap-2"><Download className="size-4" /> CSV</Button>
                        </a>
                    </div>
                </div>

                {!campaign.uses_template && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                        Esta campaña se creó cuando el envío era de texto libre. WhatsApp solo entrega mensajes masivos como
                        plantilla aprobada, así que no se puede lanzar: crea una nueva eligiendo una plantilla.
                    </div>
                )}

                <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
                    <Metrica titulo="Destinatarios" valor={campaign.total_recipients} />
                    <Metrica titulo="Enviados" valor={enviados} total={campaign.total_recipients} />
                    <Metrica titulo="Entregados" valor={entregados} total={campaign.total_recipients} tono="teal" />
                    <Metrica titulo="Leídos" valor={c.read} total={campaign.total_recipients} tono="green" />
                    <Metrica titulo="Fallidos" valor={c.failed} total={campaign.total_recipients} tono="red" />
                </div>

                <div className="space-y-1">
                    <div className="h-2 rounded-full bg-muted overflow-hidden">
                        <div className="h-full bg-green-500 transition-all" style={{ width: `${progreso}%` }} />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {resueltos} de {campaign.total_recipients} procesados
                        {campaign.started_at ? ` · empezó ${formatDate(campaign.started_at)}` : ''}
                        {campaign.completed_at ? ` · terminó ${formatDate(campaign.completed_at)}` : ''}
                    </p>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-[1fr_340px] gap-6">
                    <div className="rounded-xl border bg-card overflow-hidden">
                        <div className="flex flex-wrap gap-1 border-b px-3 pt-2">
                            {FILTROS.map(f => (
                                <button
                                    key={f.key}
                                    onClick={() => setFiltro(f.key)}
                                    className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
                                        filtro === f.key ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {f.label}
                                </button>
                            ))}
                        </div>

                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                                <tr>
                                    <th className="text-left px-4 py-2.5 font-medium">Destinatario</th>
                                    <th className="text-left px-4 py-2.5 font-medium">Estado</th>
                                    <th className="text-left px-4 py-2.5 font-medium">Motivo / momento</th>
                                    <th className="text-right px-4 py-2.5 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtrados.map(r => (
                                    <tr key={r.id} className="border-t">
                                        <td className="px-4 py-2.5">
                                            <div className="text-foreground">{r.name || '—'}</div>
                                            <div className="text-xs text-muted-foreground font-mono">{r.phone_number}</div>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${RECIPIENT_CLASS[r.status] ?? 'bg-muted'}`}>
                                                {RECIPIENT_LABEL[r.status] ?? r.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5 text-xs text-muted-foreground max-w-md">
                                            {r.status === 'skipped' ? (
                                                <span className="text-amber-600 dark:text-amber-400">{r.reason_detail}</span>
                                            ) : r.status === 'failed' ? (
                                                <>
                                                    <div className="text-red-600 dark:text-red-400">{r.reason}</div>
                                                    {r.reason_detail && <div className="opacity-70 line-clamp-2">{r.reason_detail}</div>}
                                                </>
                                            ) : (
                                                <>
                                                    {r.read_at ? `Leído ${formatDate(r.read_at)}`
                                                        : r.delivered_at ? `Entregado ${formatDate(r.delivered_at)}`
                                                        : r.sent_at ? `Enviado ${formatDate(r.sent_at)}`
                                                        : 'En espera'}
                                                </>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-right">
                                            {r.conversation_id && (
                                                <Link href={`/chat?conversation=${r.conversation_id}`} className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs">
                                                    <MessageSquare className="size-3.5" /> Ver chat
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {filtrados.length === 0 && (
                                    <tr><td colSpan={4} className="px-4 py-8 text-center text-sm text-muted-foreground">Nada en este filtro.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <aside className="space-y-3">
                        <div className="text-xs font-medium uppercase tracking-wider text-muted-foreground">Lo que recibió el cliente</div>
                        <WhatsAppPreview
                            model={modelo}
                            verifiedName={campaign.instance?.name}
                            empty="Esta campaña no guarda la plantilla."
                        />
                    </aside>
                </div>
            </div>
        </>
    );
}

function Metrica({ titulo, valor, total, tono }) {
    const color = {
        teal: 'text-teal-600 dark:text-teal-400',
        green: 'text-green-600 dark:text-green-400',
        red: 'text-red-600 dark:text-red-400',
    }[tono] ?? 'text-foreground';

    const porcentaje = total > 0 ? Math.round((valor / total) * 100) : null;

    return (
        <div className="rounded-xl border bg-card px-4 py-3">
            <div className="text-xs uppercase tracking-wide text-muted-foreground">{titulo}</div>
            <div className={`text-2xl font-semibold ${color}`}>{valor}</div>
            {porcentaje !== null && <div className="text-xs text-muted-foreground">{porcentaje}%</div>}
        </div>
    );
}

/**
 * La burbuja con lo que se envió, reconstruida desde la instantánea de la
 * plantilla y el primer destinatario. El texto ya resuelto lo calcula el
 * backend (`preview`), que es quien sabe de dónde salió cada dato.
 */
function modeloDePlantilla(campaign) {
    const componentes = campaign.template_components ?? [];
    const header = componentes.find(c => c.type === 'HEADER');
    const footer = componentes.find(c => c.type === 'FOOTER');
    const botones = componentes.find(c => c.type === 'BUTTONS');
    const formato = (header?.format || 'TEXT').toUpperCase();

    return {
        header: header
            ? (formato === 'TEXT'
                // Ya resuelto en el backend, que es quien sabe de dónde salió
                // cada dato; el texto crudo llevaría los {{1}} a la vista.
                ? { text: campaign.preview_header ?? header.text ?? '' }
                : { text: '', mediaFormat: formato, mediaUrl: campaign.header_media_url })
            : null,
        body: { text: campaign.preview ?? '' },
        footer: footer ? { text: footer.text } : null,
        buttons: (botones?.buttons ?? []).map(b => ({
            type: b.type ?? 'QUICK_REPLY',
            text: b.text ?? '',
            url: b.url ?? '',
            phone_number: b.phone_number ?? '',
        })),
    };
}

CampaignsShow.layout = page => <AppLayout breadcrumb={['Campañas', 'Detalle']}>{page}</AppLayout>;
