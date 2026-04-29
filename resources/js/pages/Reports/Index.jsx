import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { ArrowUp, Clock, Inbox, MessageCircle, Users, AlertCircle, BarChart3, User as UserIcon, X } from 'lucide-react';

export default function ReportsIndex(props) {
    const { mode, filters, agents } = props;
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [userId, setUserId] = useState(filters.user_id ?? '');

    function applyFilters(e) {
        e?.preventDefault?.();
        router.get(route('reports.index'), {
            from,
            to,
            user_id: userId || undefined,
        }, { preserveState: true, preserveScroll: true });
    }

    function quickRange(days) {
        const end = new Date();
        const start = new Date();
        start.setDate(end.getDate() - days);
        const f = start.toISOString().slice(0, 10);
        const t = end.toISOString().slice(0, 10);
        setFrom(f);
        setTo(t);
        router.get(route('reports.index'), { from: f, to: t, user_id: userId || undefined }, { preserveState: true, preserveScroll: true });
    }

    function clearUser() {
        setUserId('');
        router.get(route('reports.index'), { from, to }, { preserveState: true, preserveScroll: true });
    }

    return (
        <>
            <Head title="Reportes" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Reportes de agentes</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Tiempos de respuesta, mensajes enviados y conversaciones sin responder.
                        </p>
                    </div>
                </div>

                <form onSubmit={applyFilters} className="flex items-end flex-wrap gap-3 rounded-xl border bg-card p-4">
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">Desde</label>
                        <input
                            type="date"
                            value={from}
                            onChange={e => setFrom(e.target.value)}
                            className="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">Hasta</label>
                        <input
                            type="date"
                            value={to}
                            onChange={e => setTo(e.target.value)}
                            className="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </div>
                    <div className="space-y-1 min-w-[220px]">
                        <label className="text-xs font-medium text-muted-foreground">Agente</label>
                        <select
                            value={userId}
                            onChange={e => setUserId(e.target.value)}
                            className="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        >
                            <option value="">Todos los agentes</option>
                            {agents.map(a => (
                                <option key={a.id} value={a.id}>{a.name}</option>
                            ))}
                        </select>
                    </div>
                    <Button type="submit">Aplicar</Button>
                    {filters.user_id && (
                        <Button type="button" variant="outline" onClick={clearUser} className="gap-1.5">
                            <X className="size-3.5" /> Quitar agente
                        </Button>
                    )}
                    <div className="flex gap-1 ml-auto">
                        <Button type="button" size="sm" variant="outline" onClick={() => quickRange(7)}>7d</Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => quickRange(30)}>30d</Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => quickRange(90)}>90d</Button>
                    </div>
                </form>

                {mode === 'agent'
                    ? <AgentReport report={props.agentReport} />
                    : <CompanyReport report={props.report} />}
            </div>
        </>
    );
}

function CompanyReport({ report }) {
    const totals = report.totals;
    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Tile icon={Inbox} label="Mensajes recibidos" value={totals.inbound} tone="blue" />
                <Tile icon={MessageCircle} label="Mensajes enviados" value={totals.outbound} tone="green" />
                <Tile icon={Clock} label="Tiempo promedio de respuesta" value={formatSeconds(totals.avg_response_seconds)} sub={`${totals.response_count} respuestas`} tone="amber" />
                <Tile icon={AlertCircle} label="Sin responder" value={countUnanswered(report)} sub={`${totals.open_conversations} abiertas`} tone="red" />
            </div>

            <div className="rounded-xl border bg-card overflow-hidden mt-6">
                <div className="px-4 py-3 border-b bg-muted/30 text-sm font-medium flex items-center gap-2">
                    <Users className="size-4" /> Desempeño por agente
                </div>
                {report.agents.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 text-center">
                        <BarChart3 className="size-12 text-muted-foreground/40 mb-4" />
                        <p className="text-sm text-muted-foreground">No hay actividad de agentes en este rango.</p>
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-muted/30 text-xs uppercase text-muted-foreground">
                            <tr>
                                <th className="text-left px-4 py-2 font-medium">Agente</th>
                                <th className="text-right px-4 py-2 font-medium">Enviados</th>
                                <th className="text-right px-4 py-2 font-medium">Conversaciones</th>
                                <th className="text-right px-4 py-2 font-medium">Resp. promedio</th>
                                <th className="text-right px-4 py-2 font-medium">Más rápido</th>
                                <th className="text-right px-4 py-2 font-medium">Más lento</th>
                                <th className="text-right px-4 py-2 font-medium">Sin responder</th>
                                <th className="text-right px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.agents.map(a => (
                                <tr key={a.user_id} className="border-t">
                                    <td className="px-4 py-2">
                                        <div className="font-medium text-foreground">{a.name}</div>
                                        <div className="text-xs text-muted-foreground">{a.email}</div>
                                    </td>
                                    <td className="px-4 py-2 text-right font-mono">
                                        <span className="inline-flex items-center gap-1 text-green-700 dark:text-green-300">
                                            <ArrowUp className="size-3.5" /> {a.messages_sent}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2 text-right">{a.conversations_handled}</td>
                                    <td className="px-4 py-2 text-right font-mono">{formatSeconds(a.avg_response_seconds)}</td>
                                    <td className="px-4 py-2 text-right font-mono text-muted-foreground">{formatSeconds(a.fastest_response_seconds)}</td>
                                    <td className="px-4 py-2 text-right font-mono text-muted-foreground">{formatSeconds(a.slowest_response_seconds)}</td>
                                    <td className="px-4 py-2 text-right">
                                        {a.unanswered_count > 0 ? (
                                            <span className="inline-flex items-center gap-1 text-red-600 dark:text-red-400 font-medium">
                                                <AlertCircle className="size-3.5" /> {a.unanswered_count}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">0</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get(route('reports.index'), { user_id: a.user_id }, { preserveState: true })}
                                        >
                                            Ver detalle
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {totals.unanswered_unassigned > 0 && (
                <div className="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 px-4 py-3 text-sm text-amber-800 dark:text-amber-200 flex items-center gap-2 mt-4">
                    <AlertCircle className="size-4" />
                    Hay <strong>{totals.unanswered_unassigned}</strong> conversaciones sin responder y sin agente asignado.
                </div>
            )}
        </>
    );
}

function AgentReport({ report }) {
    const { user, totals, by_day, by_conversation, unanswered } = report;
    return (
        <>
            <div className="rounded-xl border bg-card p-5 flex items-center gap-3">
                <div className="flex size-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300">
                    <UserIcon className="size-6" />
                </div>
                <div>
                    <p className="text-lg font-semibold text-foreground">{user?.name ?? 'Agente'}</p>
                    <p className="text-sm text-muted-foreground">{user?.email}</p>
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Tile icon={MessageCircle} label="Mensajes enviados" value={totals.messages_sent} tone="green" />
                <Tile icon={Users} label="Conversaciones" value={totals.conversations_handled} tone="blue" />
                <Tile icon={Clock} label="Resp. promedio" value={formatSeconds(totals.avg_response_seconds)} sub={`${totals.response_count} respuestas`} tone="amber" />
                <Tile icon={AlertCircle} label="Sin responder" value={totals.unanswered_count} sub="asignadas a este agente" tone="red" />
            </div>

            <div className="grid gap-6 lg:grid-cols-2 mt-2">
                <div className="rounded-xl border bg-card overflow-hidden">
                    <div className="px-4 py-3 border-b bg-muted/30 text-sm font-medium">Por día</div>
                    {by_day.length === 0 ? (
                        <EmptyHint text="Sin actividad en este rango." />
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-muted/30 text-xs uppercase text-muted-foreground">
                                <tr>
                                    <th className="text-left px-4 py-2 font-medium">Fecha</th>
                                    <th className="text-right px-4 py-2 font-medium">Enviados</th>
                                    <th className="text-right px-4 py-2 font-medium">Respuestas</th>
                                    <th className="text-right px-4 py-2 font-medium">Resp. promedio</th>
                                </tr>
                            </thead>
                            <tbody>
                                {by_day.map(d => (
                                    <tr key={d.date} className="border-t">
                                        <td className="px-4 py-2 font-mono text-foreground">{d.date}</td>
                                        <td className="px-4 py-2 text-right">{d.messages_sent}</td>
                                        <td className="px-4 py-2 text-right text-muted-foreground">{d.response_count}</td>
                                        <td className="px-4 py-2 text-right font-mono">{formatSeconds(d.avg_response_seconds)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="rounded-xl border bg-card overflow-hidden">
                    <div className="px-4 py-3 border-b bg-muted/30 text-sm font-medium">Por conversación</div>
                    {by_conversation.length === 0 ? (
                        <EmptyHint text="Sin conversaciones atendidas." />
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-muted/30 text-xs uppercase text-muted-foreground">
                                <tr>
                                    <th className="text-left px-4 py-2 font-medium">Contacto</th>
                                    <th className="text-right px-4 py-2 font-medium">Enviados</th>
                                    <th className="text-right px-4 py-2 font-medium">Resp. prom.</th>
                                </tr>
                            </thead>
                            <tbody>
                                {by_conversation.slice(0, 50).map(c => (
                                    <tr key={c.conversation_id} className="border-t">
                                        <td className="px-4 py-2">
                                            <div className="font-medium text-foreground">{c.name || 'Sin nombre'}</div>
                                            <div className="text-xs text-muted-foreground font-mono">{c.phone}</div>
                                        </td>
                                        <td className="px-4 py-2 text-right">{c.messages_sent}</td>
                                        <td className="px-4 py-2 text-right font-mono">{formatSeconds(c.avg_response_seconds)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            <div className="rounded-xl border bg-card overflow-hidden mt-2">
                <div className="px-4 py-3 border-b bg-muted/30 text-sm font-medium flex items-center gap-2 text-red-700 dark:text-red-300">
                    <AlertCircle className="size-4" /> Conversaciones sin responder asignadas a este agente
                </div>
                {unanswered.length === 0 ? (
                    <EmptyHint text="Sin conversaciones pendientes. ¡Buen trabajo!" />
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-muted/30 text-xs uppercase text-muted-foreground">
                            <tr>
                                <th className="text-left px-4 py-2 font-medium">Contacto</th>
                                <th className="text-left px-4 py-2 font-medium">Teléfono</th>
                                <th className="text-left px-4 py-2 font-medium">Último mensaje</th>
                            </tr>
                        </thead>
                        <tbody>
                            {unanswered.map(u => (
                                <tr key={u.id} className="border-t">
                                    <td className="px-4 py-2 font-medium text-foreground">{u.name || 'Sin nombre'}</td>
                                    <td className="px-4 py-2 font-mono text-muted-foreground">{u.phone}</td>
                                    <td className="px-4 py-2 text-xs text-muted-foreground">
                                        {u.last_message_at ? new Date(u.last_message_at).toLocaleString() : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </>
    );
}

function EmptyHint({ text }) {
    return (
        <div className="px-4 py-6 text-center text-sm text-muted-foreground">{text}</div>
    );
}

function countUnanswered(report) {
    const fromAgents = report.agents.reduce((acc, a) => acc + a.unanswered_count, 0);
    return fromAgents + report.totals.unanswered_unassigned;
}

function formatSeconds(seconds) {
    if (seconds == null) return '—';
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return s > 0 ? `${m}m ${s}s` : `${m}m`;
    }
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

function Tile({ icon: Icon, label, value, sub, tone = 'blue' }) {
    const tones = {
        blue: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300',
        green: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300',
        amber: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300',
        red: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300',
    };
    return (
        <div className="rounded-xl border bg-card p-4">
            <div className="flex items-center gap-3">
                <div className={`flex size-9 items-center justify-center rounded-lg ${tones[tone]}`}>
                    <Icon className="size-4" />
                </div>
                <div className="flex-1">
                    <p className="text-xs font-medium text-muted-foreground uppercase">{label}</p>
                    <p className="text-xl font-semibold text-foreground mt-0.5">{value}</p>
                    {sub && <p className="text-xs text-muted-foreground mt-0.5">{sub}</p>}
                </div>
            </div>
        </div>
    );
}

ReportsIndex.layout = page => <AppLayout breadcrumb={['Reportes']}>{page}</AppLayout>;
