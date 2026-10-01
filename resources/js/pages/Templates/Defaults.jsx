import { useCallback, useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import CabeceraModulo from '@/components/cabecera-modulo';
import { WhatsAppPreview, templateToModel } from './preview';
import {
    Sparkles,
    Loader2,
    RefreshCw,
    CheckCircle2,
    AlertTriangle,
    Wrench,
    Megaphone,
    KeyRound,
    Plug,
} from 'lucide-react';

const CATEGORY_STYLES = {
    MARKETING: 'bg-fuchsia-500/15 text-fuchsia-600 dark:text-fuchsia-400 ring-1 ring-inset ring-fuchsia-500/30',
    UTILITY: 'bg-info/15 text-info ring-1 ring-inset ring-info/30',
    AUTHENTICATION: 'bg-primary/15 text-accent-foreground ring-1 ring-inset ring-primary/30',
};
const CATEGORY_ICONS = { MARKETING: Megaphone, UTILITY: Wrench, AUTHENTICATION: KeyRound };

const STATUS_STYLES = {
    APPROVED: 'bg-success/15 text-success ring-1 ring-inset ring-success/30',
    PENDING: 'bg-warning/15 text-warning ring-1 ring-inset ring-warning/30',
    REJECTED: 'bg-destructive/15 text-destructive ring-1 ring-inset ring-destructive/30',
};

export default function TemplatesDefaults({ instances = [], catalog = {}, negocio = '' }) {
    const [instanceId, setInstanceId] = useState(instances[0]?.id ?? null);
    const [statuses, setStatuses] = useState({}); // { [key]: [{id, language, status}, ...] | null }
    const [syncing, setSyncing] = useState(null); // key en curso
    const [results, setResults] = useState({}); // { [key]: { ok, message } }

    const entries = Object.entries(catalog);

    const loadStatuses = useCallback(async () => {
        if (!instanceId) return;
        for (const [key] of entries) {
            try {
                const res = await axios.get(`/api/templates/family/${key}`, { params: { instance_id: instanceId } });
                setStatuses(prev => ({ ...prev, [key]: res.data.data || [] }));
            } catch {
                setStatuses(prev => ({ ...prev, [key]: null }));
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [instanceId]);

    useEffect(() => { loadStatuses(); }, [loadStatuses]);

    async function sync(key) {
        if (!instanceId) return;
        setSyncing(key);
        setResults(prev => ({ ...prev, [key]: null }));
        try {
            const res = await axios.post(`/api/templates/defaults/${key}/sync`, { instance_id: instanceId });
            setResults(prev => ({
                ...prev,
                [key]: {
                    ok: true,
                    message: res.data.already_exists
                        ? 'Ya estaba sincronizada con esta instancia.'
                        : 'Creada en Meta. Queda pendiente de aprobación.',
                },
            }));
            loadStatuses();
        } catch (err) {
            setResults(prev => ({
                ...prev,
                [key]: { ok: false, message: err?.response?.data?.message || 'No se pudo sincronizar con Meta.' },
            }));
        } finally {
            setSyncing(null);
        }
    }

    return (
        <>
            <Head title="Plantillas por defecto" />
            <div className="max-w-4xl mx-auto px-4 py-6 space-y-6">
                <CabeceraModulo
                    icono={Sparkles}
                    volver={route('templates.index')}
                    titulo="Plantillas por defecto Integra CRM"
                    descripcion="Catálogo de plantillas mantenido por Integra CRM, disponible para todas las empresas. Sincronízalas con tu WABA en un clic; quedan pendientes de aprobación por Meta como cualquier otra plantilla."
                >
                    {instances.length > 1 && (
                        <select
                            value={instanceId ?? ''}
                            onChange={e => setInstanceId(Number(e.target.value) || null)}
                            className="h-9 rounded-lg border border-input bg-card/80 px-3 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring/50"
                        >
                            {instances.map(i => (
                                <option key={i.id} value={i.id}>{i.name} ({i.display_phone_number})</option>
                            ))}
                        </select>
                    )}
                </CabeceraModulo>

                {instances.length === 0 && (
                    <div className="rounded-2xl border border-dashed py-12 text-center text-sm text-muted-foreground">
                        No hay instancias con WABA configurado. Configura una instancia para sincronizar plantillas.
                    </div>
                )}

                {instances.length > 0 && entries.length === 0 && (
                    <div className="rounded-2xl border border-dashed py-12 text-center text-sm text-muted-foreground">
                        Aún no hay plantillas en el catálogo por defecto.
                    </div>
                )}

                {entries.map(([key, entry]) => {
                    const CatIcon = CATEGORY_ICONS[entry.category] ?? Wrench;
                    const variants = statuses[key];
                    const result = results[key];
                    return (
                        <div key={key} className="rounded-2xl border bg-card/60 p-5 space-y-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <h2 className="font-bold text-foreground">{entry.label}</h2>
                                        <span className={`inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wide rounded px-1.5 py-0.5 ${CATEGORY_STYLES[entry.category] ?? ''}`}>
                                            <CatIcon className="size-3" /> {entry.category}
                                        </span>
                                        <span className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground/70 bg-muted/60 rounded px-1.5 py-0.5">
                                            {entry.language}
                                        </span>
                                        {/* De dónde viene. La plantilla es suya y
                                            vive en su cuenta de Meta, pero el texto
                                            y el envío los pone Integra: sin decirlo,
                                            aparecen dos plantillas nuevas en su
                                            catálogo sin que nadie sepa quién las
                                            puso ni quién las dispara. */}
                                        {entry.origen && (
                                            <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wide rounded px-1.5 py-0.5 bg-success/15 text-success ring-1 ring-inset ring-success/30">
                                                <Plug className="size-3" /> De {entry.origen}
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-xs text-muted-foreground mt-1 font-mono">{key}</p>
                                    <p className="text-sm text-muted-foreground mt-1.5 max-w-xl">{entry.description}</p>
                                    {entry.origen && (
                                        <p className="text-xs text-muted-foreground/80 mt-1 max-w-xl">
                                            La plantilla queda en tu cuenta de Meta y es tuya. El texto lo pone {entry.origen},
                                            y es {entry.origen} quien la envía con el documento adjunto.
                                        </p>
                                    )}
                                </div>
                                <Button onClick={() => sync(key)} disabled={!instanceId || syncing === key} className="gap-2 shrink-0">
                                    {syncing === key ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                                    Sincronizar con Meta
                                </Button>
                            </div>

                            <div className="grid md:grid-cols-2 gap-4">
                                <WhatsAppPreview model={templateToModel(entry)} verifiedName={negocio || 'Tu negocio'} />

                                <div className="space-y-3">
                                    {entry.variable_hints && Object.keys(entry.variable_hints).length > 0 && (
                                        <div>
                                            <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground mb-1.5">Variables</p>
                                            <ul className="space-y-1">
                                                {Object.entries(entry.variable_hints).map(([n, hint]) => (
                                                    <li key={n} className="text-xs text-muted-foreground">
                                                        <span className="font-mono font-semibold text-foreground">{`{{${n}}}`}</span> — {hint}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    <div>
                                        <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground mb-1.5">Estado en esta instancia</p>
                                        {variants === undefined ? (
                                            <p className="text-xs text-muted-foreground flex items-center gap-1.5"><Loader2 className="size-3.5 animate-spin" /> Consultando…</p>
                                        ) : !variants || variants.length === 0 ? (
                                            <p className="text-xs text-muted-foreground">Aún no sincronizada.</p>
                                        ) : (
                                            <div className="flex flex-wrap gap-1.5">
                                                {variants.map(v => (
                                                    <span key={v.id} className={`inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wide rounded px-1.5 py-0.5 ${STATUS_STYLES[v.status] ?? 'bg-muted/60 text-muted-foreground'}`}>
                                                        {v.language} · {v.status}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    {result && (
                                        <div className={`flex items-start gap-2 rounded-lg px-3 py-2 text-xs ${
                                            result.ok
                                                ? 'border border-success/30 bg-success/10 text-success'
                                                : 'border border-destructive/30 bg-destructive/10 text-destructive'
                                        }`}>
                                            {result.ok ? <CheckCircle2 className="size-3.5 mt-0.5 shrink-0" /> : <AlertTriangle className="size-3.5 mt-0.5 shrink-0" />}
                                            <span>{result.message}</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </>
    );
}

TemplatesDefaults.layout = page => <AppLayout breadcrumb={['Plantillas', 'Plantillas por defecto']}>{page}</AppLayout>;
