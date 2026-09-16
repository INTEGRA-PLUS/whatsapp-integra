import { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    AlertTriangle, BadgeCheck, CheckCircle2, ChevronDown, Eye, EyeOff, FileText, KeyRound,
    ListChecks, Loader2, Lock, MessageCircle, Plus, Save, ShieldAlert, ShieldCheck, Sparkles,
    Trash2, UserCheck, XCircle,
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

/* ───────────────────────── El traspaso a un asesor ───────────────────────── */

const TRASPASOS = [
    {
        id: 'menos_cargado',
        titulo: 'Al asesor menos cargado',
        detalle: 'El que menos conversaciones abiertas tenga. A igualdad, el que lleva más tiempo sin recibir una.',
    },
    {
        id: 'equipo',
        titulo: 'Al menos cargado de un equipo',
        detalle: 'Reparte igual, pero solo entre las personas que elijas. Útil si soporte y cartera no atienden lo mismo.',
    },
    {
        id: 'fijo',
        titulo: 'Siempre a la misma persona',
        detalle: 'Todos los traspasos le llegan a quien elijas, tenga la carga que tenga.',
    },
    {
        id: 'bandeja',
        titulo: 'A nadie en concreto',
        detalle: 'El chat queda sin dueño y visible para todo el equipo, que lo toma quien pueda.',
    },
];

/**
 * A dónde va el chat cuando la IA se rinde o el cliente pide un humano.
 *
 * Antes no se elegía: iba siempre al asesor menos cargado. Eso funciona en un
 * equipo donde todos hacen lo mismo, y no en una empresa donde soporte técnico y
 * cartera son dos mundos — ahí la factura acaba en manos del que instala antenas
 * solo porque tenía un hueco.
 */
function TraspasoCard({ state, busy, save }) {
    const t = state.traspaso ?? {};
    const asesores = t.asesores ?? [];

    const [estrategia, setEstrategia] = useState(t.estrategia ?? 'menos_cargado');
    const [usuarioId, setUsuarioId] = useState(t.usuario_id ?? null);
    const [equipo, setEquipo] = useState(t.equipo ?? []);

    function guardar(cambios) {
        const siguiente = { estrategia, usuario_id: usuarioId, equipo, ...cambios };

        setEstrategia(siguiente.estrategia);
        setUsuarioId(siguiente.usuario_id);
        setEquipo(siguiente.equipo);

        save({ traspaso: siguiente }, 'Traspaso a un asesor actualizado.');
    }

    function alternarDelEquipo(id) {
        guardar({ equipo: equipo.includes(id) ? equipo.filter(x => x !== id) : [...equipo, id] });
    }

    // Sin nadie con rol de atención no hay a quién mandar nada, y elegir entre
    // una lista vacía solo confunde.
    const sinAsesores = asesores.length === 0;

    return (
        <Card>
            <div className="p-6 space-y-4">
                <div>
                    <div className="flex items-center gap-2">
                        <UserCheck className="size-4 text-accent-foreground" />
                        <p className="text-sm font-semibold text-foreground">Cuando hay que pasar a una persona</p>
                    </div>
                    <p className="text-xs text-muted-foreground mt-1.5 leading-relaxed">
                        Si el cliente pide un asesor, o la IA no sabe responder, el chat se le entrega a alguien
                        de tu equipo con un resumen de lo que pedía. Aquí eliges a quién.
                    </p>
                </div>

                {sinAsesores && (
                    <div className="flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-[11px] text-warning">
                        <AlertTriangle className="size-3.5 mt-0.5 shrink-0" />
                        <span>No hay nadie con rol de administrador o asesor. Los chats quedarán en la bandeja general.</span>
                    </div>
                )}

                <div className="grid gap-2 sm:grid-cols-2">
                    {TRASPASOS.map(opcion => {
                        const activa = estrategia === opcion.id;

                        return (
                            <button
                                key={opcion.id}
                                type="button"
                                disabled={busy}
                                onClick={() => guardar({ estrategia: opcion.id })}
                                className={`rounded-xl border p-3.5 text-left transition ${
                                    activa
                                        ? 'border-teal-500/60 bg-teal-500/5 ring-1 ring-teal-500/30'
                                        : 'border-border/60 hover:border-border hover:bg-muted/30'
                                }`}
                            >
                                <div className="flex items-start gap-2">
                                    <span className={`mt-0.5 size-3.5 shrink-0 rounded-full border-2 ${
                                        activa ? 'border-teal-500 bg-teal-500' : 'border-border'
                                    }`} />
                                    <div className="min-w-0">
                                        <p className="text-xs font-medium text-foreground">{opcion.titulo}</p>
                                        <p className="text-[11px] text-muted-foreground mt-0.5 leading-relaxed">
                                            {opcion.detalle}
                                        </p>
                                    </div>
                                </div>
                            </button>
                        );
                    })}
                </div>

                {estrategia === 'fijo' && (
                    <div className="space-y-2">
                        <label className="block text-xs font-medium text-muted-foreground" htmlFor="traspaso-asesor">
                            Quién recibe los traspasos
                        </label>
                        <select
                            id="traspaso-asesor"
                            value={usuarioId ?? ''}
                            disabled={busy || sinAsesores}
                            onChange={e => guardar({ usuario_id: e.target.value ? Number(e.target.value) : null })}
                            className="w-full rounded-xl border border-border bg-background px-3.5 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                        >
                            <option value="">Elige un asesor…</option>
                            {asesores.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </select>
                    </div>
                )}

                {estrategia === 'equipo' && (
                    <div className="space-y-2">
                        <label className="block text-xs font-medium text-muted-foreground">
                            Quiénes están en el equipo
                            <span className="text-muted-foreground/60"> ({equipo.length}/{t.max_equipo ?? 25})</span>
                        </label>
                        <div className="flex flex-wrap gap-2">
                            {asesores.map(a => {
                                const dentro = equipo.includes(a.id);

                                return (
                                    <button
                                        key={a.id}
                                        type="button"
                                        disabled={busy}
                                        onClick={() => alternarDelEquipo(a.id)}
                                        className={`rounded-lg border px-3 py-1.5 text-[11px] transition ${
                                            dentro
                                                ? 'border-teal-500/60 bg-teal-500/10 text-foreground'
                                                : 'border-border/60 text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {dentro && <CheckCircle2 className="mr-1 inline size-3 text-teal-500" />}
                                        {a.name}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* Lo que pasa si la configuración se rompe. Se dice aquí porque
                    es una decisión de producto que sorprende: quien elige «a esta
                    persona» espera que sea siempre, y conviene que sepa que un
                    cliente nunca se queda esperando a nadie. */}
                {(estrategia === 'fijo' || estrategia === 'equipo') && (
                    <p className="text-[11px] text-muted-foreground/80 leading-relaxed">
                        Si quien elegiste no puede recibir el chat —se dio de baja, o el equipo entero está
                        inactivo— se lo entregamos igualmente al asesor menos cargado. Dejar al cliente esperando
                        a nadie es peor que saltarse tu preferencia.
                    </p>
                )}
            </div>
        </Card>
    );
}

/* ───────────────────────── Los documentos ───────────────────────── */

const ESTADOS = {
    procesando: { texto: 'Leyendo…', clase: 'text-muted-foreground', Icono: Loader2, gira: true },
    listo:      { texto: 'Listo',    clase: 'text-emerald-600 dark:text-emerald-400', Icono: CheckCircle2 },
    fallido:    { texto: 'No se pudo leer', clase: 'text-destructive', Icono: XCircle },
};

/**
 * Lo que la empresa sube para que su IA sepa de qué habla.
 *
 * El campo de texto de arriba son 4.000 caracteres —página y media— y no cabe
 * un reglamento. Aquí caben cinco documentos, y de cada uno la IA usa sólo los
 * párrafos que responden a lo que preguntó el cliente.
 *
 * **Se sondea mientras haya alguno leyéndose.** Un PDF grande tarda decenas de
 * segundos y el job corre en otra parte: sin el sondeo el admin ve «Leyendo…»
 * para siempre y recarga la página, que es como acaba subiendo el mismo
 * documento tres veces.
 */
function DocumentosCard({ permitido, nombreDelComplemento }) {
    const [documentos, setDocumentos] = useState([]);
    const [cargando, setCargando] = useState(true);
    const [subiendo, setSubiendo] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => { cargar(); }, []);

    // El sondeo se monta y se desmonta con los documentos que están en curso:
    // en cuanto no queda ninguno, deja de correr solo.
    useEffect(() => {
        if (!documentos.some(d => d.estado === 'procesando')) return;

        const id = setInterval(cargar, 3000);
        return () => clearInterval(id);
    }, [documentos]);

    async function cargar() {
        try {
            const { data } = await axios.get('/api/settings/ai-flow/documentos');
            setDocumentos(data.documentos);
        } catch {
            setError('No se pudo cargar la lista de documentos.');
        } finally {
            setCargando(false);
        }
    }

    async function subir(e) {
        const archivo = e.target.files?.[0];
        // El input se limpia siempre: si no, volver a elegir el mismo fichero
        // tras un error no dispara ningún evento y parece que el botón murió.
        e.target.value = '';

        if (!archivo || subiendo) return;

        setSubiendo(true); setError('');

        const cuerpo = new FormData();
        cuerpo.append('archivo', archivo);

        try {
            const { data } = await axios.post('/api/settings/ai-flow/documentos', cuerpo);
            setDocumentos(data.documentos);
        } catch (err) {
            setError(err.response?.data?.message ?? 'No se pudo subir el archivo.');
        } finally {
            setSubiendo(false);
        }
    }

    async function borrar(documento) {
        setError('');
        try {
            const { data } = await axios.delete(`/api/settings/ai-flow/documentos/${documento.id}`);
            setDocumentos(data.documentos);
        } catch {
            setError('No se pudo borrar el documento.');
        }
    }

    async function reprocesar(documento) {
        setError('');
        try {
            const { data } = await axios.post(`/api/settings/ai-flow/documentos/${documento.id}/reprocesar`);
            setDocumentos(data.documentos);
        } catch {
            setError('No se pudo volver a intentarlo.');
        }
    }

    const lleno = documentos.length >= 5;

    return (
        <Card>
            <div className="p-6 space-y-4">
                <div className="flex items-start justify-between gap-6">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <FileText className="size-4 text-accent-foreground" />
                            <p className="text-sm font-semibold text-foreground">Documentos de tu empresa</p>
                        </div>
                        <p className="text-xs text-muted-foreground mt-1.5 leading-relaxed">
                            Sube tu tarifario, tu reglamento o tus preguntas frecuentes. De cada consulta la IA
                            usará sólo los párrafos que respondan a lo que preguntó el cliente.
                        </p>
                    </div>

                    <label className={`shrink-0 ${permitido && !lleno ? '' : 'pointer-events-none opacity-50'}`}>
                        <input
                            type="file"
                            className="sr-only"
                            accept=".pdf,.docx,.xlsx,.csv,.txt"
                            disabled={!permitido || lleno || subiendo}
                            onChange={subir}
                        />
                        <span className="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-border bg-background px-3.5 py-2 text-xs font-medium hover:bg-muted">
                            {subiendo ? <Loader2 className="size-3.5 animate-spin" /> : <Plus className="size-3.5" />}
                            {subiendo ? 'Subiendo…' : 'Subir'}
                        </span>
                    </label>
                </div>

                {!permitido && (
                    <div className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-[11px] text-muted-foreground">
                        <Lock className="size-3.5 mt-0.5 shrink-0" />
                        <span>Va con la IA de los chats, que no está en {nombreDelComplemento}. Contacta con un administrador.</span>
                    </div>
                )}

                {error && (
                    <div className="flex items-start gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-[11px] text-destructive">
                        <AlertTriangle className="size-3.5 mt-0.5 shrink-0" />
                        <span>{error}</span>
                    </div>
                )}

                {cargando ? (
                    <p className="text-xs text-muted-foreground">Cargando…</p>
                ) : documentos.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border/70 px-4 py-6 text-center text-xs text-muted-foreground">
                        Todavía no has subido ninguno. Se aceptan PDF, Word, Excel, CSV y texto, hasta 10 MB.
                    </p>
                ) : (
                    <div className="space-y-2">
                        {documentos.map(d => {
                            const estado = ESTADOS[d.estado] ?? ESTADOS.procesando;
                            const { Icono } = estado;

                            return (
                                <div key={d.id} className="rounded-xl border border-border/60 bg-muted/20 px-3.5 py-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            {/* `break-all` y no `truncate`: el nombre del
                                                archivo es lo único con lo que el admin
                                                distingue dos tarifarios, y cortarlo a los
                                                treinta caracteres los deja idénticos. */}
                                            <p className="text-xs font-medium text-foreground break-all">{d.nombre}</p>
                                            <div className="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[11px]">
                                                <span className={`inline-flex items-center gap-1 ${estado.clase}`}>
                                                    <Icono className={`size-3 ${estado.gira ? 'animate-spin' : ''}`} />
                                                    {estado.texto}
                                                </span>
                                                <span className="text-muted-foreground/70">{d.tamano}</span>
                                                {d.estado === 'listo' && (
                                                    <span className="text-muted-foreground/70">
                                                        {d.fragmentos} {d.fragmentos === 1 ? 'fragmento' : 'fragmentos'}
                                                    </span>
                                                )}
                                                {/* La fecha se enseña siempre y no escondida en un
                                                    tooltip: un tarifario viejo que nadie borró es
                                                    peor que no tener nada, porque la IA va a citar
                                                    precios que ya no existen sonando igual de
                                                    segura. */}
                                                <span className="text-muted-foreground/70">{fecha(d.subido_el)}</span>
                                                {/* Si ha contestado o no. Es el único número que
                                                    distingue un documento que trabaja de uno que
                                                    nadie consulta: sin él, los dos se ven igual. */}
                                                {d.estado === 'listo' && (
                                                    <span className={d.usos > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground/70'}>
                                                        {d.usos > 0
                                                            ? `ha respondido ${d.usos} ${d.usos === 1 ? 'vez' : 'veces'}`
                                                            : 'sin usar todavía'}
                                                    </span>
                                                )}
                                            </div>
                                            {d.antiguo && (
                                                <p className="mt-1.5 flex items-start gap-1.5 text-[11px] text-amber-600 dark:text-amber-400 leading-relaxed">
                                                    <AlertTriangle className="size-3 mt-0.5 shrink-0" />
                                                    Lleva más de seis meses. Si trae precios o plazos, comprueba que sigan vigentes:
                                                    la IA los va a citar con la misma seguridad estén o no al día.
                                                </p>
                                            )}
                                            {d.motivo && (
                                                <p className="mt-1.5 text-[11px] text-destructive leading-relaxed">{d.motivo}</p>
                                            )}
                                        </div>

                                        <div className="flex shrink-0 items-center gap-1">
                                            {d.estado === 'fallido' && permitido && (
                                                <button
                                                    onClick={() => reprocesar(d)}
                                                    className="rounded-lg px-2 py-1 text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground"
                                                >
                                                    Reintentar
                                                </button>
                                            )}
                                            <button
                                                onClick={() => borrar(d)}
                                                title="Borrar"
                                                className="rounded-lg p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                            >
                                                <Trash2 className="size-3.5" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                <p className="text-[11px] text-muted-foreground/80 leading-relaxed">
                    Un PDF escaneado —una foto del papel— no tiene texto y no se puede leer. Si pasa, te lo decimos aquí.
                </p>

                {documentos.some(d => d.estado === 'listo') && <Probador permitido={permitido} />}
            </div>
        </Card>
    );
}

/**
 * Preguntar como preguntaría un cliente, y ver qué encuentra.
 *
 * Convierte «he subido un PDF y no sé si sirve» en algo que se comprueba en diez
 * segundos. Sin esto, la única forma de saberlo es esperar a que un cliente real
 * pregunte y luego leerse la conversación.
 *
 * No cuenta como uso: si contara, el admin inflaría con sus propias pruebas el
 * mismo número al que mira para decidir si un documento sirve.
 */
function Probador({ permitido }) {
    const [pregunta, setPregunta] = useState('');
    const [resultado, setResultado] = useState(null);
    const [buscando, setBuscando] = useState(false);

    async function probar(e) {
        e.preventDefault();
        if (!pregunta.trim() || buscando) return;

        setBuscando(true);
        try {
            const { data } = await axios.post('/api/settings/ai-flow/documentos/probar', { pregunta });
            setResultado(data);
        } catch {
            setResultado({ fragmentos: [], error: true });
        } finally {
            setBuscando(false);
        }
    }

    return (
        <div className="rounded-xl border border-border/60 bg-muted/10 p-4 space-y-3">
            <div>
                <p className="text-xs font-medium text-foreground">Pruébalo</p>
                <p className="text-[11px] text-muted-foreground mt-0.5 leading-relaxed">
                    Escribe lo que preguntaría un cliente y mira qué encuentra. No se le manda nada a nadie.
                </p>
            </div>

            <form onSubmit={probar} className="flex gap-2">
                <input
                    id="probador-pregunta"
                    value={pregunta}
                    onChange={e => setPregunta(e.target.value)}
                    maxLength={500}
                    placeholder="¿a cuántos años puedo pagar la casa?"
                    disabled={!permitido}
                    className="flex-1 rounded-xl border border-border bg-background px-3.5 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                />
                <Button type="submit" disabled={!permitido || buscando || !pregunta.trim()} className="shrink-0 gap-1.5">
                    {buscando ? <Loader2 className="size-3.5 animate-spin" /> : <Sparkles className="size-3.5" />}
                    Buscar
                </Button>
            </form>

            {resultado && (
                <div className="space-y-2">
                    {resultado.error ? (
                        <p className="text-[11px] text-destructive">No se pudo buscar. Inténtalo otra vez.</p>
                    ) : resultado.fragmentos.length === 0 ? (
                        <p className="text-[11px] text-muted-foreground leading-relaxed">
                            No encontró nada. Si esperabas que sí, puede que tus documentos no cubran esa pregunta
                            —o que esté escrita con palabras que no aparecen en ellos.
                        </p>
                    ) : (
                        resultado.fragmentos.map((f, i) => (
                            <div key={i} className="rounded-lg border border-border/50 bg-background px-3 py-2">
                                <div className="flex items-baseline justify-between gap-3">
                                    <span className="text-[11px] font-medium text-muted-foreground break-all">
                                        {f.origen ?? 'sin origen'}
                                    </span>
                                    {/* Sin vectores no hay parecido que enseñar: se buscó
                                        por palabras, y un número aquí se leería como si
                                        lo hubiera. */}
                                    {f.parecido !== null && f.parecido !== undefined && (
                                        <span className="shrink-0 text-[11px] tabular-nums text-muted-foreground/70">
                                            {Math.round(f.parecido * 100)}%
                                        </span>
                                    )}
                                </div>
                                <p className="mt-1 text-[11px] text-foreground/90 leading-relaxed">{f.texto}</p>
                            </div>
                        ))
                    )}

                    {!resultado.error && !resultado.por_significado && (
                        <p className="text-[11px] text-muted-foreground/80 leading-relaxed">
                            Ahora mismo se busca por palabras sueltas, no por significado: si preguntas por
                            «préstamo» no encontrará el párrafo que habla de «crédito». Avísale a un administrador.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

/** «hace 2 días», que es lo que se quiere saber de un tarifario. */
function fecha(iso) {
    if (!iso) return '';

    const dias = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);

    if (dias < 1) return 'hoy';
    if (dias === 1) return 'ayer';
    if (dias < 30) return `hace ${dias} días`;

    return new Date(iso).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' });
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

                    {/* De qué documentos saca lo que sabe */}
                    <DocumentosCard permitido={complemento.chat} nombreDelComplemento={complemento.nombre} />

                    {/* A quién le llega el chat cuando la IA se rinde */}
                    <TraspasoCard state={state} busy={busy} save={save} />

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
