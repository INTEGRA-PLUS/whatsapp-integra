import { useState, useEffect } from 'react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Field, inputClass } from '@/components/ProviderConnectForm';
import {
    Pencil, CheckCircle2, XCircle, Power, Plug, RefreshCw, AlertTriangle, Loader2,
    KeyRound, HelpCircle, ChevronDown, ArrowRight, MessageCircle, Bot, Server, ShieldCheck,
} from 'lucide-react';

const MCP_PERFILES = [
    { id: 'lectura', label: 'Lectura', hint: 'Sólo consultar. Es el perfil con el que se emiten los tokens hoy.' },
    { id: 'soporte', label: 'Soporte', hint: 'Consultar, con la cédula y el celular del cliente sin enmascarar.' },
    { id: 'escritura', label: 'Escritura', hint: 'Además, registrar pagos, abrir reportes y pedir prórrogas.' },
];

/**
 * La credencial del asistente de Integra (el servidor MCP del cliente).
 *
 * Se pega a mano y no hay asistente de conexión como en Integra v1 porque no
 * puede haberlo: el token se emite dentro del contenedor de cada cliente con
 * `php artisan mcp:token` y el servidor lo enseña UNA sola vez —en su base sólo
 * queda el hash—. No existe ningún endpoint que podamos llamar para que nos lo
 * dé, así que alguien lo copia y lo pega aquí.
 *
 * Hasta ahora esos pasos vivían en `docs/mcp-integra.md`, que nadie fuera del
 * equipo abre: conectar el asistente exigía pedírselo a un desarrollador. Por
 * eso la guía va dentro del panel, abierta mientras no haya nada conectado.
 *
 * El token no vuelve nunca del backend, así que el formulario no puede
 * precargarlo: cuando ya hay uno guardado se muestra el estado y se pide el
 * token entero sólo si se quiere reemplazar.
 */
export default function McpAssistant({ showToast }) {
    const [state, setState] = useState(null);
    const [form, setForm] = useState({ base_url: '', token: '', perfil: 'lectura' });
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState(false);

    useEffect(() => { load(); }, []);

    async function load() {
        try {
            const { data } = await axios.get('/api/integrations/mcp');
            setState(data);
            setForm(f => ({ ...f, base_url: data.base_url ?? '', perfil: data.perfil ?? 'lectura' }));
        } catch {
            setState({ connected: false, perfiles: MCP_PERFILES.map(p => p.id) });
        }
    }

    async function submit(e) {
        e.preventDefault();
        if (busy) return;
        setBusy(true);
        try {
            const { data } = await axios.post('/api/integrations/mcp', form);
            setState(data);
            setForm(f => ({ ...f, token: '' }));
            setEditing(false);
            showToast(`Asistente conectado: ${data.tools} herramientas disponibles.`);
        } catch (err) {
            showToast(err?.response?.data?.message ?? 'No se pudo conectar.', 'error');
        } finally {
            setBusy(false);
        }
    }

    async function verify() {
        setBusy(true);
        try {
            const { data } = await axios.post('/api/integrations/mcp/verify');
            setState(data);
            showToast(data.ok ? 'La credencial sigue funcionando.' : data.message, data.ok ? 'success' : 'error');
        } catch (err) {
            showToast(err?.response?.data?.message ?? 'No se pudo verificar.', 'error');
        } finally {
            setBusy(false);
        }
    }

    async function disconnect() {
        setBusy(true);
        try {
            const { data } = await axios.delete('/api/integrations/mcp');
            setState(data);
            setForm({ base_url: '', token: '', perfil: 'lectura' });
            showToast('Asistente desconectado.');
        } finally {
            setBusy(false);
        }
    }

    if (!state) {
        return <div className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" /> Cargando…</div>;
    }

    const showForm = !state.connected || editing;

    return (
        <div className="flex flex-col gap-4">
            {/* `key` para que la guía se vuelva a abrir sola al desconectar. */}
            <McpGuide key={state.connected ? 'on' : 'off'} defaultOpen={!state.connected} />

            {state.connected && (
                <div className="rounded-lg border bg-muted/20 px-4 py-3">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        <span className="inline-flex items-center gap-1.5 font-medium text-success">
                            <CheckCircle2 className="size-4" /> Conectado
                        </span>
                        <span className="text-muted-foreground">{state.tools} herramientas</span>
                        <span className="text-muted-foreground">Perfil: {state.perfil}</span>
                    </div>
                    <p className="mt-1 break-all text-xs text-muted-foreground">{state.base_url}</p>
                    {state.last_error && (
                        <p className="mt-2 text-xs text-destructive">{state.last_error}</p>
                    )}
                </div>
            )}

            {state.status === 'error' && !state.connected && (
                <div className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-xs text-destructive">
                    <XCircle className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        La credencial guardada dejó de funcionar{state.last_error ? `: ${state.last_error}` : '.'} La IA
                        ya no consulta tu Integra. Emite un token nuevo y pégalo abajo.
                    </span>
                </div>
            )}

            {/* Que la empresa lo haya conectado no basta: el puente lo enciende la
                plataforma con MCP_INTEGRA_KEY. Sin ella la conexión se guarda y
                se verifica bien, y aun así la IA no llega nunca a usarla. */}
            {state.connected && state.plataforma === false && (
                <div className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 px-4 py-3 text-xs text-warning">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        Tu credencial está bien, pero el servicio todavía no está activado en este servidor, así
                        que la IA aún no la usa. Pídele a soporte que lo active; no hace falta volver a conectar.
                    </span>
                </div>
            )}

            {/* El aviso que evita el diagnóstico imposible: hoy todos los tokens
                emitidos son de lectura, así que registrar un pago, abrir un
                reporte o pedir una prórroga van a fallar en el servidor por
                mucho que el permiso esté concedido en el Flujo IA. */}
            {state.connected && !state.writes && (
                <div className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 px-4 py-3 text-xs text-warning">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        Este token es de sólo lectura: la IA puede consultar, pero no registrar pagos, abrir
                        reportes ni pedir prórrogas. Para eso hace falta un token de perfil <strong>escritura</strong>,
                        que se emite en tu servidor de Integra.
                    </span>
                </div>
            )}

            {showForm ? (
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Field label="URL de tu Integra">
                        <input
                            className={inputClass}
                            value={form.base_url}
                            onChange={e => setForm(f => ({ ...f, base_url: e.target.value }))}
                            placeholder="https://miempresa.com"
                            autoComplete="off"
                        />
                        <p className="text-xs text-muted-foreground">
                            El dominio donde entras a Integra. Se le añade <code>/software/mcp</code> solo.
                        </p>
                    </Field>

                    <Field label="Token del asistente">
                        <div className="relative">
                            <KeyRound className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                className={`${inputClass} pl-10`}
                                type="password"
                                value={form.token}
                                onChange={e => setForm(f => ({ ...f, token: e.target.value }))}
                                placeholder="itg_…"
                                autoComplete="off"
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Empieza por <code>itg_</code>. Solo se muestra una vez al emitirlo: si lo perdiste,
                            emite otro.
                        </p>
                    </Field>

                    <Field label="Perfil del token">
                        <div className="flex flex-col gap-2">
                            {MCP_PERFILES.map(p => (
                                <label key={p.id} className="flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2 has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                    <input
                                        type="radio"
                                        name="mcp-perfil"
                                        className="mt-0.5"
                                        checked={form.perfil === p.id}
                                        onChange={() => setForm(f => ({ ...f, perfil: p.id }))}
                                    />
                                    <span className="min-w-0">
                                        <span className="block text-sm text-foreground">{p.label}</span>
                                        <span className="block text-xs text-muted-foreground">{p.hint}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            El mismo con el que lo emitiste. Si no coincide, las acciones fallarán en tu Integra.
                        </p>
                    </Field>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={busy || !form.base_url.trim() || !form.token.trim()} className="gap-2">
                            {busy ? <Loader2 className="size-4 animate-spin" /> : <Plug className="size-4" />}
                            Probar y conectar
                        </Button>
                        {editing && (
                            <Button type="button" variant="outline" onClick={() => { setEditing(false); setForm(f => ({ ...f, token: '' })); }}>
                                Cancelar
                            </Button>
                        )}
                    </div>
                </form>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" onClick={verify} disabled={busy} className="gap-2">
                        {busy ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                        Verificar
                    </Button>
                    <Button type="button" variant="outline" onClick={() => setEditing(true)} disabled={busy} className="gap-2">
                        <Pencil className="size-4" /> Cambiar token
                    </Button>
                    <Button type="button" variant="outline" onClick={disconnect} disabled={busy} className="gap-2 text-destructive">
                        <Power className="size-4" /> Desconectar
                    </Button>
                </div>
            )}
        </div>
    );
}

/**
 * Cómo funciona, escrito para quien administra la empresa y no para nosotros.
 *
 * Tres dudas que salían siempre al conectarlo: de dónde sale el token, por qué
 * la IA no cobra si «ya le di permiso», y si la IA puede facturar. Las tres
 * tienen respuesta aquí para que no haga falta abrir un ticket.
 */
function McpGuide({ defaultOpen }) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <div className="rounded-lg border bg-muted/20">
            <button type="button" onClick={() => setOpen(o => !o)}
                className="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-medium text-foreground">
                <span className="inline-flex items-center gap-2">
                    <HelpCircle className="size-4 text-muted-foreground" /> Cómo funciona y cómo conectarlo
                </span>
                <ChevronDown className={`size-4 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="space-y-5 border-t px-4 py-4 text-xs text-muted-foreground">
                    <p>
                        Tu Integra trae un <strong className="text-foreground">asistente</strong>: un servicio que le deja a
                        una IA consultar clientes, cartera, contratos, facturas y reportes. Al conectarlo aquí, la IA
                        que contesta tus WhatsApp lo usa para responder con los datos reales del cliente, en vez de
                        pasar el chat a un asesor.
                    </p>

                    {/* El camino del dato. Lo que tranquiliza es la última caja:
                        el token se queda en el CRM y no viaja a ningún otro sitio. */}
                    <div className="flex flex-wrap items-center gap-2">
                        <GuideNode Icon={MessageCircle}>Tu cliente pregunta</GuideNode>
                        <ArrowRight className="size-3.5 shrink-0" />
                        <GuideNode Icon={Bot}>La IA del CRM</GuideNode>
                        <ArrowRight className="size-3.5 shrink-0" />
                        <GuideNode Icon={Server}>Tu Integra responde</GuideNode>
                    </div>

                    <ol className="space-y-3">
                        <GuideStep n={1} title="Emite un token en tu Integra">
                            Lo hace quien administra el servidor de Integra de tu empresa (tu proveedor o tu equipo de
                            sistemas), con este comando dentro del servidor:
                            <code className="mt-1.5 block rounded-md bg-muted px-2.5 py-1.5 font-mono text-[11px] text-foreground">
                                php artisan mcp:token --perfil=lectura
                            </code>
                            <span className="mt-1.5 block">
                                Se muestra <strong className="text-foreground">una sola vez</strong>: cópialo en ese
                                momento. Si se pierde, se emite otro.
                            </span>
                        </GuideStep>
                        <GuideStep n={2} title="Pégalo aquí abajo">
                            Con el dominio de tu Integra y el perfil con el que se emitió. Antes de guardar lo probamos
                            contra tu servidor: si no abre, no se guarda y te decimos por qué.
                        </GuideStep>
                        <GuideStep n={3} title="Decide hasta dónde llega la IA">
                            En <a href="/ia" className="underline underline-offset-2 hover:text-foreground">IA → IA en los menús → Hasta dónde puede llegar</a>{' '}
                            eliges si sólo consulta, o si además puede radicar y cobrar.
                        </GuideStep>
                    </ol>

                    <div className="space-y-2">
                        <p className="font-medium text-foreground">Qué perfil elegir</p>
                        <div className="overflow-x-auto">
                            <table className="w-full text-left">
                                <tbody>
                                    {[
                                        ['lectura', 'Consultar facturas, contratos, pagos y reportes. Empieza por aquí.'],
                                        ['soporte', 'Lo mismo, viendo cédula y celular completos (en lectura salen enmascarados).'],
                                        ['escritura', 'Además, registrar un pago, abrir un reporte y pedir una prórroga.'],
                                    ].map(([perfil, what]) => (
                                        <tr key={perfil} className="border-b last:border-0">
                                            <td className="py-1.5 pr-4 font-mono text-foreground">{perfil}</td>
                                            <td className="py-1.5">{what}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p>
                            El perfil <code>nomina</code> existe en Integra y aquí no se acepta: la IA conversa con
                            desconocidos y no tiene por qué poder abrir la nómina de tu empresa.
                        </p>
                    </div>

                    {/* Las dos llaves: la pregunta de «ya le di permiso y no cobra»
                        siempre era un token de lectura con el permiso encendido. */}
                    <div className="flex gap-2.5 rounded-lg border bg-card p-3">
                        <ShieldCheck className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <p>
                            <strong className="text-foreground">Para que la IA cobre o radique hacen falta dos llaves</strong>:
                            un token de perfil <code>escritura</code> y el permiso encendido en la pantalla de IA. Con una
                            sola, la IA le dice al cliente que lo pasa con un asesor en vez de prometer algo que no ocurre.
                        </p>
                    </div>

                    <div className="space-y-1">
                        <p className="font-medium text-foreground">Lo que la IA no puede hacer, con ningún perfil</p>
                        <p>
                            Emitir ni anular facturas, tocar la numeración DIAN, cortar o reconectar el servicio,
                            configurar equipos de red ni borrar nada. El asistente de Integra no tiene esas funciones.
                        </p>
                    </div>

                    <div className="space-y-1">
                        <p className="font-medium text-foreground">Si la IA deja de saber cosas</p>
                        <p>
                            Pulsa <em>Verificar</em>. Un token revocado en tu servidor no nos avisa: la IA sigue
                            contestando, pero sin datos. Verificar lo detecta y te dice qué respondió tu Integra.
                        </p>
                    </div>
                </div>
            )}
        </div>
    );
}

function GuideNode({ Icon, children }) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-md border bg-card px-2 py-1 text-foreground">
            <Icon className="size-3.5 text-muted-foreground" /> {children}
        </span>
    );
}

function GuideStep({ n, title, children }) {
    return (
        <li className="flex gap-3">
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                {n}
            </span>
            <div className="min-w-0">
                <p className="font-medium text-foreground">{title}</p>
                <div className="mt-0.5">{children}</div>
            </div>
        </li>
    );
}
