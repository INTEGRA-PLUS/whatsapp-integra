import { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    AlertTriangle, BadgeCheck, CheckCircle2, ChevronDown, Eye, EyeOff, FileText, KeyRound,
    ListChecks, Loader2, Lock, MessageCircle, Plus, Save, ShieldAlert, ShieldCheck, Sparkles,
    Trash2, XCircle,
} from 'lucide-react';

/**
 * La IA que responde sola.
 *
 * Vivía dentro de `/settings` como la octava pestaña, entre «Apariencia» y
 * «Horarios» — 643 líneas en un fichero de 3.142, y la función que se vende
 * escondida donde sólo la encuentra quien ya sabe que existe.
 *
 * La pantalla tiene dos caras y la que decide cuál es el plan, no el permiso:
 *
 *   sin el complemento contratado → qué hace y cómo pedirlo
 *   con el complemento            → el secreto de activación, y detrás, todo
 *
 * El candado de verdad NO está aquí sino en `AiFlowSettingsController`, que
 * responde 402 a quien no lo tiene aunque llegue por curl. Esto es sólo lo que
 * se ve.
 */

/* ───────────────────────── Utilidades visuales ───────────────────────── */

// Copiadas de Settings/Index.jsx en vez de importadas: son diez líneas, y
// sacarlas a un módulo compartido obligaba a tocar un fichero de 3.000 que otra
// sesión está editando esta semana.
function Card({ children, className = '' }) {
    return (
        <div className={`rounded-2xl border border-border/60 bg-card/50 backdrop-blur-sm shadow-sm ${className}`}>
            {children}
        </div>
    );
}

/* ───────────────────────── La configuración ───────────────────────── */

const AI_PERMISSION_LABELS = {
    leer:      { title: 'Consultar',  desc: 'Leer facturas, contratos y estado del servicio del cliente.' },
    radicados: { title: 'Radicar',    desc: 'Crear radicados de falla a nombre del cliente.' },
    pagos:     { title: 'Cobrar',     desc: 'Enviar enlaces de pago. Genera cobros reales.' },
};

function AiSwitch({ checked, disabled, onChange }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={!!checked}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${checked ? 'bg-primary' : 'bg-muted'}`}
        >
            <span className={`inline-block size-5 transform rounded-full bg-white shadow transition-transform ${checked ? 'translate-x-[22px]' : 'translate-x-0.5'}`} />
        </button>
    );
}

/**
 * Quién es la IA de esta empresa.
 *
 * Va antes de los dos interruptores a propósito: la identidad la comparten los
 * dos flujos, y ponerla dentro de la tarjeta de uno de ellos haría pensar que
 * sólo aplica a ése.
 *
 * A diferencia de los interruptores, esto no se guarda al teclear: son campos
 * de texto y guardar en cada letra dejaría a los clientes hablando con un
 * asistente a medio renombrar.
 */
function AsistenteCard({ state, busy, save }) {
    const saved = state.assistant;
    const max = saved.limits;

    const asDraft = a => ({
        nombre_asistente: a.nombre_asistente ?? '',
        tratamiento: a.tratamiento ?? 'tu',
        tono: a.tono ?? '',
        conocimiento: a.conocimiento ?? '',
        limites: [...(a.limites ?? [])],
    });

    const [draft, setDraft] = useState(() => asDraft(saved));
    const [nuevo, setNuevo] = useState('');

    // Al guardar, el backend devuelve el perfil saneado —recortado, sin
    // duplicados—: el formulario tiene que mostrar eso y no lo que se escribió.
    useEffect(() => { setDraft(asDraft(saved)); }, [JSON.stringify(saved)]);

    const dirty = JSON.stringify(draft) !== JSON.stringify(asDraft(saved));
    const set = (k, v) => setDraft(d => ({ ...d, [k]: v }));

    function addLimite() {
        const v = nuevo.trim();
        if (!v || draft.limites.length >= max.limites || draft.limites.includes(v)) return;
        set('limites', [...draft.limites, v]);
        setNuevo('');
    }

    // La vista previa se arma aquí mientras hay cambios sin guardar; en cuanto
    // se guarda manda la del backend, que es la que refleja lo que de verdad
    // va a recibir el flujo.
    const preview = dirty
        ? (draft.nombre_asistente.trim() && saved.empresa
            ? `${draft.nombre_asistente.trim()}, el asistente virtual de ${saved.empresa}`
            : draft.nombre_asistente.trim()
                || (saved.empresa ? `el asistente virtual de ${saved.empresa}` : 'el asistente virtual de esta empresa'))
        : saved.presentacion;

    return (
        <Card>
            <div className="p-6 space-y-5">
                <div>
                    <div className="flex items-center gap-2">
                        <BadgeCheck className="size-4 text-teal-600 dark:text-teal-400" />
                        <p className="text-sm font-semibold text-foreground">Cómo se presenta</p>
                    </div>
                    <p className="text-xs text-muted-foreground mt-1.5 leading-relaxed">
                        Aplica a las dos IA. Si no pones nada, se presenta como el asistente de tu
                        empresa sin nombre propio.
                    </p>
                </div>

                {/* Vista previa: lo que el cliente va a leer */}
                <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                    <p className="text-[11px] font-medium text-muted-foreground">Se presentará como</p>
                    <p className="text-sm text-foreground mt-1">{preview}</p>
                </div>

                <div className="grid gap-5 sm:grid-cols-2">
                    <div className="space-y-2">
                        <label className="block text-xs font-medium text-muted-foreground">
                            Nombre del asistente <span className="text-muted-foreground/60">(opcional)</span>
                        </label>
                        <input
                            value={draft.nombre_asistente}
                            onChange={e => set('nombre_asistente', e.target.value)}
                            maxLength={max.nombre_asistente}
                            placeholder="Sofía"
                            className="w-full rounded-xl border border-border bg-background px-3.5 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                        />
                    </div>

                    <div className="space-y-2">
                        <label className="block text-xs font-medium text-muted-foreground">Cómo trata al cliente</label>
                        <div className="flex gap-2">
                            {[['tu', 'Tú'], ['usted', 'Usted']].map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => set('tratamiento', value)}
                                    className={`flex-1 rounded-xl border px-3 py-2.5 text-sm transition-colors ${
                                        draft.tratamiento === value
                                            ? 'border-teal-500 bg-teal-500/10 text-teal-700 dark:text-teal-300 font-medium'
                                            : 'border-border text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="space-y-2">
                    <label className="block text-xs font-medium text-muted-foreground">Tono</label>
                    <input
                        value={draft.tono}
                        onChange={e => set('tono', e.target.value)}
                        maxLength={max.tono}
                        placeholder="cordial, claro y profesional"
                        className="w-full rounded-xl border border-border bg-background px-3.5 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                    />
                </div>

                <div className="space-y-2">
                    <div className="flex items-baseline justify-between gap-3">
                        <label className="block text-xs font-medium text-muted-foreground">Qué sabe de tu empresa</label>
                        <span className="text-[11px] text-muted-foreground/70">
                            {draft.conocimiento.length}/{max.conocimiento}
                        </span>
                    </div>
                    <textarea
                        value={draft.conocimiento}
                        onChange={e => set('conocimiento', e.target.value)}
                        maxLength={max.conocimiento}
                        rows={6}
                        placeholder={'Horarios de atención\nSedes y direcciones\nServicios que ofrecen\nPreguntas frecuentes'}
                        className="w-full rounded-xl border border-border bg-background px-3.5 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40 resize-y"
                    />
                    <p className="text-[11px] text-muted-foreground/80 leading-relaxed">
                        Sólo podrá afirmar lo que escribas aquí. De lo que no esté, dirá que no lo sabe
                        con certeza y ofrecerá pasar el chat a un agente.
                    </p>
                </div>

                <div className="space-y-2">
                    <label className="block text-xs font-medium text-muted-foreground">
                        De qué no debe hablar <span className="text-muted-foreground/60">({draft.limites.length}/{max.limites})</span>
                    </label>

                    {draft.limites.length > 0 && (
                        <div className="space-y-2">
                            {draft.limites.map(l => (
                                <div key={l} className="flex items-center gap-2 rounded-lg border border-border/60 bg-muted/20 px-3 py-2">
                                    <span className="flex-1 text-sm text-foreground break-words">{l}</span>
                                    <button
                                        type="button"
                                        onClick={() => set('limites', draft.limites.filter(x => x !== l))}
                                        className="text-muted-foreground hover:text-destructive shrink-0"
                                        aria-label="Quitar"
                                    >
                                        <Trash2 className="size-3.5" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="flex gap-2">
                        <input
                            value={nuevo}
                            onChange={e => setNuevo(e.target.value)}
                            onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); addLimite(); } }}
                            maxLength={max.limite}
                            disabled={draft.limites.length >= max.limites}
                            placeholder="No dar plazos de entrega"
                            className="flex-1 rounded-xl border border-border bg-background px-3.5 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40 disabled:opacity-50"
                        />
                        <Button
                            type="button"
                            onClick={addLimite}
                            disabled={!nuevo.trim() || draft.limites.length >= max.limites}
                            variant="outline"
                            className="gap-1.5 shrink-0"
                        >
                            <Plus className="size-4" /> Añadir
                        </Button>
                    </div>
                </div>

                <div className="flex items-center justify-end gap-3 border-t border-border/60 pt-5">
                    {dirty && <span className="text-[11px] text-muted-foreground">Hay cambios sin guardar</span>}
                    <Button
                        onClick={() => save({ assistant: draft }, 'Perfil del asistente guardado.')}
                        disabled={busy || !dirty}
                        className="gap-2 bg-teal-600 hover:bg-teal-500 text-white"
                    >
                        {busy && <Loader2 className="size-4 animate-spin" />}
                        <Save className="size-4" />
                        Guardar
                    </Button>
                </div>
            </div>
        </Card>
    );
}

/**
 * Los dos prompts: el de la plataforma y el de la empresa.
 *
 * Tarjeta aparte de la del perfil a propósito. El perfil son campos cortos con
 * una forma clara —un nombre, un tono, una lista— y esto es un folio en blanco
 * que acaba dentro del prompt de un modelo que habla con clientes reales: no se
 * llenan con la misma cabeza ni se revisan con la misma atención.
 *
 * El base se enseña entero y de sólo lectura. Es lo que evita las dos formas de
 * perder el tiempo escribiendo aquí: repetir una regla que el base ya trae, o
 * escribir una que lo contradice y que nunca va a ganar —las reglas se repiten
 * DESPUÉS de este texto justamente para eso—.
 */
function PromptCard({ state, busy, save }) {
    const saved = state.assistant;
    const base = state.prompt ?? {};
    const max = saved.limits.instrucciones;

    const [draft, setDraft] = useState(saved.instrucciones ?? '');
    const [verBase, setVerBase] = useState(false);
    const [verCompuesto, setVerCompuesto] = useState(false);

    // El backend devuelve el texto saneado —sin marcadores de turno, sin los
    // delimitadores—: el formulario muestra eso y no lo que se escribió, o el
    // admin creería que sigue ahí lo que se le quitó.
    useEffect(() => { setDraft(saved.instrucciones ?? ''); }, [saved.instrucciones]);

    const dirty = draft !== (saved.instrucciones ?? '');

    return (
        <Card>
            <div className="p-6 space-y-5">
                <div>
                    <div className="flex items-center gap-2">
                        <FileText className="size-4 text-teal-600 dark:text-teal-400" />
                        <p className="text-sm font-semibold text-foreground">Instrucciones para la IA</p>
                    </div>
                    <p className="text-xs text-muted-foreground mt-1.5 leading-relaxed">
                        Lo que escribas aquí <span className="font-medium text-foreground">se suma</span> a las
                        instrucciones de la plataforma; no las reemplaza. Aplica a las dos IA.
                    </p>
                </div>

                {/* El prompt base: de la plataforma, igual para todas las empresas */}
                <div className="rounded-xl border border-border/60 bg-muted/20 overflow-hidden">
                    <button
                        type="button"
                        onClick={() => setVerBase(v => !v)}
                        className="flex w-full items-center gap-2 px-4 py-3 text-left hover:bg-muted/40 transition-colors"
                    >
                        <ShieldAlert className="size-4 text-muted-foreground shrink-0" />
                        <span className="flex-1 min-w-0">
                            <span className="block text-xs font-medium text-foreground">
                                Instrucciones de la plataforma
                            </span>
                            <span className="block text-[11px] text-muted-foreground mt-0.5">
                                Las tienen todas las empresas y no se pueden cambiar desde aquí
                            </span>
                        </span>
                        <ChevronDown className={`size-4 text-muted-foreground shrink-0 transition-transform ${verBase ? 'rotate-180' : ''}`} />
                    </button>

                    {verBase && (
                        <pre className="border-t border-border/60 px-4 py-3 text-[11px] leading-relaxed text-muted-foreground whitespace-pre-wrap font-mono max-h-80 overflow-y-auto">
                            {base.base}
                        </pre>
                    )}
                </div>

                {/* Lo que escribe la empresa */}
                <div className="space-y-2">
                    <div className="flex items-baseline justify-between gap-3">
                        <label className="block text-xs font-medium text-muted-foreground">
                            Instrucciones de tu empresa
                        </label>
                        <span className={`text-[11px] ${draft.length > max * 0.9 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground/70'}`}>
                            {draft.length}/{max}
                        </span>
                    </div>
                    <textarea
                        value={draft}
                        onChange={e => setDraft(e.target.value)}
                        maxLength={max}
                        rows={12}
                        placeholder={'Cómo quieres que atienda:\n\n- Saluda por el nombre del cliente cuando lo sepas.\n- Si preguntan por garantías, explica que son 12 meses y ofrece pasar con un asesor.\n- Nunca digas "no sé": di qué sí puedes hacer.\n- Despídete preguntando si necesita algo más.'}
                        className="w-full rounded-xl border border-border bg-background px-3.5 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40 resize-y leading-relaxed"
                    />
                    <p className="text-[11px] text-muted-foreground/80 leading-relaxed">
                        Son preferencias de atención: tono, orden de las respuestas, qué ofrecer y cuándo. Las
                        reglas de la plataforma —no inventar cifras, no prometer plazos, pasar a un asesor ante
                        la duda— siguen mandando por encima de lo que escribas.
                    </p>
                </div>

                {/* El resultado: lo que de verdad va a recibir el flujo */}
                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <button
                        type="button"
                        onClick={() => setVerCompuesto(v => !v)}
                        className="flex w-full items-center gap-2 px-4 py-3 text-left hover:bg-muted/40 transition-colors"
                    >
                        <Sparkles className="size-4 text-muted-foreground shrink-0" />
                        <span className="flex-1 min-w-0">
                            <span className="block text-xs font-medium text-foreground">
                                Ver el prompt completo
                            </span>
                            <span className="block text-[11px] text-muted-foreground mt-0.5">
                                Tal como quedó guardado: lo que recibe la IA en cada mensaje
                            </span>
                        </span>
                        <ChevronDown className={`size-4 text-muted-foreground shrink-0 transition-transform ${verCompuesto ? 'rotate-180' : ''}`} />
                    </button>

                    {verCompuesto && (
                        <div className="border-t border-border/60">
                            {dirty && (
                                <p className="px-4 pt-3 text-[11px] text-amber-600 dark:text-amber-400">
                                    Tienes cambios sin guardar: esto todavía no los incluye.
                                </p>
                            )}
                            <pre className="px-4 py-3 text-[11px] leading-relaxed text-muted-foreground whitespace-pre-wrap font-mono max-h-96 overflow-y-auto">
                                {base.compuesto}
                            </pre>
                        </div>
                    )}
                </div>

                <div className="flex items-center justify-end gap-3 border-t border-border/60 pt-5">
                    {dirty && <span className="text-[11px] text-muted-foreground">Hay cambios sin guardar</span>}
                    <Button
                        onClick={() => save({ assistant: { instrucciones: draft } }, 'Instrucciones guardadas.')}
                        disabled={busy || !dirty}
                        className="gap-2 bg-teal-600 hover:bg-teal-500 text-white"
                    >
                        {busy && <Loader2 className="size-4 animate-spin" />}
                        <Save className="size-4" />
                        Guardar
                    </Button>
                </div>
            </div>
        </Card>
    );
}

function Configuracion() {
    const [state, setState] = useState(null);
    const [loading, setLoading] = useState(true);
    const [secret, setSecret] = useState('');
    const [showSecret, setShowSecret] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [ok, setOk] = useState('');

    useEffect(() => { load(); }, []);

    async function load() {
        setLoading(true);
        try {
            const { data } = await axios.get('/api/settings/ai-flow');
            setState(data);
        } catch {
            setError('No se pudo cargar el estado del flujo de IA.');
        } finally {
            setLoading(false);
        }
    }

    function flash(message) {
        setOk(message);
        setError('');
        setTimeout(() => setOk(''), 4000);
    }

    async function unlock(e) {
        e.preventDefault();
        if (!secret.trim() || busy) return;
        setBusy(true); setError('');
        try {
            const { data } = await axios.post('/api/settings/ai-flow/unlock', { secret });
            setState(data);
            setSecret('');
            flash('Flujo de IA desbloqueado. Ya puedes configurarlo.');
        } catch (err) {
            setError(err.response?.data?.message ?? 'No se pudo desbloquear.');
        } finally {
            setBusy(false);
        }
    }

    async function save(patch, message) {
        setBusy(true); setError('');
        try {
            const { data } = await axios.put('/api/settings/ai-flow', patch);
            setState(data);
            flash(message);
        } catch (err) {
            setError(err.response?.data?.message ?? 'No se pudo guardar el cambio.');
        } finally {
            setBusy(false);
        }
    }

    async function lock() {
        if (busy) return;
        setBusy(true); setError('');
        try {
            const { data } = await axios.delete('/api/settings/ai-flow/unlock');
            setState(data);
            flash('Flujo de IA bloqueado. Las dos IA quedaron apagadas.');
        } catch {
            setError('No se pudo bloquear.');
        } finally {
            setBusy(false);
        }
    }

    function togglePermission(key) {
        const current = state.menus.permissions;
        const next = current.includes(key) ? current.filter(p => p !== key) : [...current, key];
        save({ permissions: next }, 'Permisos actualizados.');
    }

    if (loading) {
        return (
            <div className="flex items-center gap-3 text-sm text-muted-foreground py-12">
                <Loader2 className="size-4 animate-spin" /> Cargando…
            </div>
        );
    }

    if (!state) {
        return <p className="text-sm text-destructive py-12">{error || 'No se pudo cargar.'}</p>;
    }

    const { platform } = state;
    // Qué trae el complemento contratado. Si el servidor fuera viejo y no lo
    // mandara, se asume que sí: el candado de verdad está en el servidor y una
    // pantalla que desactiva de más es peor que una que deja intentarlo.
    const complemento = state.complemento ?? { nombre: 'tu complemento', chat: true, menus: true };

    return (
        <div className="space-y-6">
            {error && (
                <div className="flex items-start gap-2 rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                    <XCircle className="size-4 mt-0.5 shrink-0" /><span>{error}</span>
                </div>
            )}
            {ok && (
                <div className="flex items-start gap-2 rounded-xl border border-primary/30 bg-primary/10 px-4 py-3 text-sm text-accent-foreground">
                    <CheckCircle2 className="size-4 mt-0.5 shrink-0" /><span>{ok}</span>
                </div>
            )}

            {!state.unlocked ? (
                <Card>
                    <div className="p-6">
                        <div className="flex items-start gap-4">
                            <div className="rounded-xl bg-warning/10 p-3">
                                <Lock className="size-5 text-warning" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-foreground">Este apartado está bloqueado</p>
                                <p className="text-xs text-muted-foreground mt-1.5 leading-relaxed">
                                    Activar la IA pone un modelo a conversar con tus clientes reales y, según los
                                    permisos que le des, a radicar fallas y enviar cobros. Para abrirlo necesitas el
                                    secreto de activación que tiene el equipo técnico.
                                </p>

                                {!platform.secret_configured ? (
                                    <div className="mt-4 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2.5 text-xs text-warning">
                                        <AlertTriangle className="size-3.5 mt-0.5 shrink-0" />
                                        <span>El servidor todavía no tiene configurado el secreto de activación. Avisa al equipo técnico.</span>
                                    </div>
                                ) : (
                                    <form onSubmit={unlock} className="mt-5 space-y-3">
                                        <label className="block text-xs font-medium text-muted-foreground">Secreto de activación</label>
                                        <div className="relative">
                                            <KeyRound className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                                            <input
                                                type={showSecret ? 'text' : 'password'}
                                                value={secret}
                                                onChange={e => setSecret(e.target.value)}
                                                autoComplete="off"
                                                placeholder="Pégalo aquí"
                                                className="w-full rounded-xl border border-border bg-background pl-10 pr-10 py-2.5 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowSecret(v => !v)}
                                                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                            >
                                                {showSecret ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                            </button>
                                        </div>
                                        <Button type="submit" disabled={busy || !secret.trim()} className="gap-2 bg-primary hover:bg-primary text-primary-foreground">
                                            {busy && <Loader2 className="size-4 animate-spin" />}
                                            <ShieldCheck className="size-4" />
                                            Desbloquear
                                        </Button>
                                    </form>
                                )}
                            </div>
                        </div>
                    </div>
                </Card>
            ) : (
                <>
                    {/* Quién es la IA de esta empresa */}
                    <AsistenteCard state={state} busy={busy} save={save} />

                    {/* Con qué instrucciones habla */}
                    <PromptCard state={state} busy={busy} save={save} />

                    {/* IA de los chats */}
                    <Card>
                        <div className="p-6">
                            <div className="flex items-start justify-between gap-6">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <MessageCircle className="size-4 text-accent-foreground" />
                                        <p className="text-sm font-semibold text-foreground">IA en los chats</p>
                                    </div>
                                    <p className="text-xs text-muted-foreground mt-1.5 leading-relaxed">
                                        Conversa con el cliente cuando escribe algo que ningún menú reconoce. No toca
                                        datos ni ejecuta acciones: solo responde y, si no puede, deja el chat a un agente.
                                    </p>
                                    {!platform.chat_configured && (
                                        <div className="mt-3 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-[11px] text-warning">
                                            <AlertTriangle className="size-3.5 mt-0.5 shrink-0" />
                                            <span>Falta configurar el flujo de chats en el servidor.</span>
                                        </div>
                                    )}
                                    {/* El complemento barato —semáforo y resumen—
                                        no trae esto: una conversación de chat con
                                        IA cuesta trece veces un análisis de
                                        semáforo. Se dice aquí en vez de dejar que
                                        pulse y se coma un 402. */}
                                    {!complemento.chat && (
                                        <div className="mt-3 flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-[11px] text-muted-foreground">
                                            <Lock className="size-3.5 mt-0.5 shrink-0" />
                                            <span>No incluido en {complemento.nombre}. Contacta con un administrador.</span>
                                        </div>
                                    )}
                                </div>
                                <AiSwitch
                                    checked={state.chat.enabled}
                                    disabled={busy || !platform.chat_configured || (!complemento.chat && !state.chat.enabled)}
                                    onChange={v => save({ chat_enabled: v }, v ? 'IA de chats activada.' : 'IA de chats desactivada.')}
                                />
                            </div>
                        </div>
                    </Card>

                    {/* IA de los menús */}
                    <Card>
                        <div className="p-6">
                            <div className="flex items-start justify-between gap-6">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <ListChecks className="size-4 text-accent-foreground" />
                                        <p className="text-sm font-semibold text-foreground">IA en los menús</p>
                                    </div>
                                    <p className="text-xs text-muted-foreground mt-1.5 leading-relaxed">
                                        Entiende lo que pide el cliente y lo resuelve contra Integra: consulta su
                                        factura, radica una falla o le envía el enlace de pago.
                                    </p>
                                    {!platform.menus_configured && (
                                        <div className="mt-3 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-[11px] text-warning">
                                            <AlertTriangle className="size-3.5 mt-0.5 shrink-0" />
                                            <span>Falta configurar el flujo de menús en el servidor.</span>
                                        </div>
                                    )}
                                    {!complemento.menus && (
                                        <div className="mt-3 flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-[11px] text-muted-foreground">
                                            <Lock className="size-3.5 mt-0.5 shrink-0" />
                                            <span>No incluido en {complemento.nombre}. Contacta con un administrador.</span>
                                        </div>
                                    )}
                                </div>
                                <AiSwitch
                                    checked={state.menus.enabled}
                                    disabled={busy || !platform.menus_configured || (!complemento.menus && !state.menus.enabled)}
                                    onChange={v => save({ menus_enabled: v }, v ? 'IA de menús activada.' : 'IA de menús desactivada.')}
                                />
                            </div>

                            {state.menus.enabled && (
                                <div className="mt-6 border-t border-border/60 pt-5">
                                    <p className="text-xs font-semibold text-foreground">Hasta dónde puede llegar</p>
                                    <p className="text-[11px] text-muted-foreground mt-1">
                                        Consultar no compromete nada. Radicar y cobrar sí: concédelos solo si los necesitas.
                                    </p>
                                    <div className="mt-4 space-y-3">
                                        {state.menus.available.map(key => {
                                            const label = AI_PERMISSION_LABELS[key] ?? { title: key, desc: '' };
                                            const on = state.menus.permissions.includes(key);
                                            return (
                                                <div key={key} className="flex items-start justify-between gap-6">
                                                    <div className="min-w-0">
                                                        <p className="text-sm text-foreground">{label.title}</p>
                                                        <p className="text-[11px] text-muted-foreground mt-0.5">{label.desc}</p>
                                                    </div>
                                                    <AiSwitch checked={on} disabled={busy} onChange={() => togglePermission(key)} />
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                        </div>
                    </Card>

                    {/* Bloquear de nuevo */}
                    <Card>
                        <div className="p-6 flex items-start justify-between gap-6">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <ShieldAlert className="size-4 text-muted-foreground" />
                                    <p className="text-sm font-semibold text-foreground">Bloquear el apartado</p>
                                </div>
                                <p className="text-xs text-muted-foreground mt-1.5 leading-relaxed">
                                    Apaga las dos IA y vuelve a pedir el secreto para configurarlas.
                                </p>
                            </div>
                            <Button onClick={lock} disabled={busy} variant="outline" className="gap-2 shrink-0">
                                {busy && <Loader2 className="size-4 animate-spin" />}
                                <Lock className="size-4" />
                                Bloquear
                            </Button>
                        </div>
                    </Card>
                </>
            )}
        </div>
    );
}

/* ───────────────────────── Cuando no está contratado ───────────────────────── */

/**
 * No es un error, es la pantalla de venta.
 *
 * Esconder del menú lo que no se ha comprado es la forma más segura de que
 * nadie lo compre: si no aparece, nadie pregunta. Así que la entrada se ve
 * siempre y lo que cambia es esto: qué hace la función, con el vocabulario del
 * cliente, y a quién pedirla.
 *
 * Sin botón «Contratar». Mientras el pago no exista, un botón que no lleva a
 * ningún sitio es peor que ninguno: enseña que la empresa no está lista.
 */
function Venta({ plan }) {
    return (
        <div className="space-y-6">
            <Card>
                <div className="p-6 sm:p-8">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-md bg-primary/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-accent-foreground">
                            Complemento
                        </span>
                        <span className="text-xs text-muted-foreground">
                            Tu plan actual: {plan}
                        </span>
                    </div>

                    <h2 className="mt-4 text-xl font-semibold tracking-tight text-foreground">
                        Que la IA conteste los chats por ti
                    </h2>
                    <p className="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                        Responde sola a lo que escriben tus contactos, con el nombre y el tono de tu empresa y
                        con las reglas que tú le pongas. Cuando no sabe o el asunto se complica, se aparta y le
                        deja el chat a un asesor.
                    </p>

                    <div className="mt-6 grid gap-4 sm:grid-cols-3">
                        {[
                            {
                                Icon: MessageCircle,
                                titulo: 'Contesta los chats',
                                texto: 'Conversa cuando el contacto escribe algo que ningún menú reconoce, a la hora que sea.',
                            },
                            {
                                Icon: BadgeCheck,
                                titulo: 'Con tu identidad',
                                texto: 'Le pones nombre, tratamiento, tono y lo que debe saber de tu negocio. No suena a robot de nadie más.',
                            },
                            {
                                Icon: ListChecks,
                                titulo: 'Y hasta donde tú digas',
                                texto: 'Puede consultar la factura, radicar una falla o enviar el enlace de pago. Cada permiso se concede aparte.',
                            },
                        ].map(({ Icon, titulo, texto }) => (
                            <div key={titulo} className="rounded-xl border border-border/60 bg-background/40 p-4">
                                <Icon className="size-4 text-accent-foreground" />
                                <p className="mt-2.5 text-sm font-medium text-foreground">{titulo}</p>
                                <p className="mt-1 text-xs leading-relaxed text-muted-foreground">{texto}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </Card>

            <Card>
                <div className="flex items-start gap-3 p-6">
                    <div className="rounded-xl bg-warning/10 p-2.5">
                        <Lock className="size-4 text-warning" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-sm font-semibold text-foreground">
                            Este paquete no está incluido en tu plan
                        </p>
                        <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                            Contacta con un administrador para habilitarlo.
                        </p>
                    </div>
                </div>
            </Card>
        </div>
    );
}

/* ───────────────────────── La página ───────────────────────── */

export default function FlujoIaIndex({ tiene_ia, plan }) {
    return (
        <AppLayout breadcrumb={['IA que responde']}>
            <Head title="IA que responde" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                <div className="flex items-start gap-3">
                    <div className="mt-0.5 rounded-xl bg-primary/10 p-2.5 dark:bg-primary/15">
                        <Sparkles className="size-5 text-accent-foreground" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">IA que responde</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            La IA que atiende a tus contactos cuando ningún menú reconoce lo que escriben.
                        </p>
                    </div>
                </div>

                <div className="max-w-3xl">
                    {tiene_ia ? <Configuracion /> : <Venta plan={plan} />}
                </div>
            </div>
        </AppLayout>
    );
}
