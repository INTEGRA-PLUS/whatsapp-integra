import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { useAppearance } from '@/hooks/use-appearance';
import { Button } from '@/components/ui/button';
import {
    User, Lock, ShieldCheck, Monitor, Moon, Sun, SunMoon,
    Smartphone, Globe, LogOut, CheckCircle2, Clock, Palette,
    KeyRound, Eye, EyeOff, Save, Mail, UserCircle,
    MessageSquare, AlertTriangle, XCircle, ExternalLink,
    Loader2, RefreshCw, Sparkles, Webhook, BarChart3, BadgeCheck,
    Building2, Image as ImageIcon, Phone as PhoneIcon, MapPin, FileText,
    Camera, ListChecks, CalendarClock, Plus, Trash2, Pencil,
    PhoneCall, MessageCircle,
} from 'lucide-react';

const TABS = [
    { id: 'perfil',      label: 'Perfil',                   Icon: User },
    { id: 'contrasena',  label: 'Contraseña',               Icon: Lock },
    { id: 'dos-pasos',   label: 'Autenticación 2FA',        Icon: ShieldCheck },
    { id: 'sesiones',    label: 'Sesiones del navegador',    Icon: Monitor },
    { id: 'apariencia',  label: 'Apariencia',               Icon: Palette },
    { id: 'whatsapp',    label: 'WhatsApp',                 Icon: MessageSquare },
    { id: 'horarios',    label: 'Horarios',                 Icon: CalendarClock },
];

/* ─── Utilidades visuales ───────────────────────────────── */

function Card({ children, className = '' }) {
    return (
        <div className={`rounded-2xl border border-border/60 bg-card/50 backdrop-blur-sm shadow-sm ${className}`}>
            {children}
        </div>
    );
}

function CardHeader({ children, className = '' }) {
    return <div className={`border-b border-border/40 px-6 py-5 ${className}`}>{children}</div>;
}

function CardBody({ children, className = '' }) {
    return <div className={`px-6 py-6 ${className}`}>{children}</div>;
}

function Field({ label, icon: IconComponent, children, error }) {
    return (
        <div className="flex flex-col gap-2">
            <label className="flex items-center gap-2 text-sm font-medium text-foreground">
                {IconComponent && <IconComponent className="size-3.5 text-muted-foreground" />}
                {label}
            </label>
            {children}
            {error && (
                <p className="text-xs text-red-500 dark:text-red-400 flex items-center gap-1">
                    <span className="size-1 rounded-full bg-red-500 inline-block" />
                    {error}
                </p>
            )}
        </div>
    );
}

function Input({ className = '', ...props }) {
    return (
        <input
            className={`w-full rounded-xl border border-border/70 bg-background/80 px-4 py-2.5 text-sm text-foreground placeholder:text-muted-foreground/60 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-500/50 hover:border-border ${className}`}
            {...props}
        />
    );
}

function SectionHeader({ title, description, icon: IconComponent }) {
    return (
        <div className="flex items-start gap-3">
            {IconComponent && (
                <div className="mt-0.5 rounded-xl bg-teal-500/10 dark:bg-teal-500/15 p-2.5">
                    <IconComponent className="size-5 text-teal-600 dark:text-teal-400" />
                </div>
            )}
            <div>
                <h2 className="text-lg font-semibold text-foreground tracking-tight">{title}</h2>
                {description && <p className="mt-0.5 text-sm text-muted-foreground leading-relaxed">{description}</p>}
            </div>
        </div>
    );
}

function getInitials(name) {
    if (!name) return '??';
    return name
        .split(' ')
        .slice(0, 2)
        .map((n) => n[0])
        .join('')
        .toUpperCase();
}

/* ───────────────────────── Perfil ───────────────────────── */
function TabPerfil() {
    const { auth, errors } = usePage().props;
    const user = auth?.user ?? {};
    const [form, setForm] = useState({ name: user.name ?? '', email: user.email ?? '' });

    function handleSubmit(e) {
        e.preventDefault();
        router.put(route('settings.profile'), form);
    }

    return (
        <div className="space-y-6">
            <SectionHeader
                icon={User}
                title="Información del perfil"
                description="Actualiza tu nombre y dirección de correo electrónico"
            />
            <Card>
                <CardBody>
                    {/* Avatar row */}
                    <div className="flex items-center gap-5 mb-8 pb-6 border-b border-border/40">
                        <div className="relative group">
                            <div className="size-20 rounded-2xl bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center text-white text-2xl font-bold shadow-lg shadow-teal-500/20 ring-4 ring-teal-500/10">
                                {getInitials(user.name)}
                            </div>
                            <div className="absolute -bottom-1 -right-1 size-6 rounded-full bg-green-500 border-[3px] border-card flex items-center justify-center">
                                <CheckCircle2 className="size-3 text-white" />
                            </div>
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-base font-semibold text-foreground truncate">{user.name ?? 'Sin nombre'}</p>
                            <p className="text-sm text-muted-foreground truncate">{user.email ?? 'Sin correo'}</p>
                            <span className="inline-flex items-center gap-1 mt-2 text-xs font-medium text-teal-600 dark:text-teal-400 bg-teal-500/10 dark:bg-teal-500/15 px-2.5 py-1 rounded-full">
                                <CheckCircle2 className="size-3" />
                                Cuenta activa
                            </span>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="flex flex-col gap-5 max-w-lg">
                        <Field label="Nombre completo" icon={UserCircle} error={errors?.name}>
                            <Input
                                value={form.name}
                                onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                                placeholder="Ingresa tu nombre"
                            />
                        </Field>
                        <Field label="Correo electrónico" icon={Mail} error={errors?.email}>
                            <Input
                                type="email"
                                value={form.email}
                                onChange={e => setForm(f => ({ ...f, email: e.target.value }))}
                                placeholder="tu@correo.com"
                            />
                        </Field>
                        <div className="pt-2">
                            <Button type="submit" className="bg-teal-600 hover:bg-teal-500 text-white border-0 rounded-xl px-6 gap-2 shadow-md shadow-teal-600/20 transition-all duration-200 hover:shadow-lg hover:shadow-teal-600/30 hover:-translate-y-px">
                                <Save className="size-4" />
                                Guardar cambios
                            </Button>
                        </div>
                    </form>
                </CardBody>
            </Card>
        </div>
    );
}

/* ───────────────────────── Contraseña ───────────────────────── */
function TabContrasena() {
    const { errors } = usePage().props;
    const [form, setForm] = useState({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const [showCurrent, setShowCurrent] = useState(false);
    const [showNew, setShowNew] = useState(false);

    function handleSubmit(e) {
        e.preventDefault();
        router.put(route('settings.password'), form, {
            onSuccess: () => setForm({ current_password: '', password: '', password_confirmation: '' }),
        });
    }

    return (
        <div className="space-y-6">
            <SectionHeader
                icon={Lock}
                title="Actualizar contraseña"
                description="Usa una contraseña larga y única para mayor seguridad"
            />
            <Card>
                <CardBody>
                    <form onSubmit={handleSubmit} className="flex flex-col gap-5 max-w-lg">
                        <Field label="Contraseña actual" icon={KeyRound} error={errors?.current_password}>
                            <div className="relative">
                                <Input
                                    type={showCurrent ? 'text' : 'password'}
                                    value={form.current_password}
                                    onChange={e => setForm(f => ({ ...f, current_password: e.target.value }))}
                                    placeholder="••••••••"
                                    className="pr-10"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowCurrent(!showCurrent)}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
                                >
                                    {showCurrent ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                </button>
                            </div>
                        </Field>

                        <div className="h-px bg-border/40" />

                        <Field label="Nueva contraseña" icon={Lock} error={errors?.password}>
                            <div className="relative">
                                <Input
                                    type={showNew ? 'text' : 'password'}
                                    value={form.password}
                                    onChange={e => setForm(f => ({ ...f, password: e.target.value }))}
                                    placeholder="Mínimo 8 caracteres"
                                    className="pr-10"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowNew(!showNew)}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
                                >
                                    {showNew ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                </button>
                            </div>
                        </Field>

                        <Field label="Confirmar nueva contraseña" icon={ShieldCheck}>
                            <Input
                                type="password"
                                value={form.password_confirmation}
                                onChange={e => setForm(f => ({ ...f, password_confirmation: e.target.value }))}
                                placeholder="Repite la nueva contraseña"
                            />
                        </Field>

                        {/* Strength indicator */}
                        {form.password && (
                            <div className="flex items-center gap-2">
                                <div className="flex-1 flex gap-1">
                                    {[1, 2, 3, 4].map(i => (
                                        <div
                                            key={i}
                                            className={`h-1 flex-1 rounded-full transition-colors duration-300 ${
                                                form.password.length >= i * 3
                                                    ? form.password.length >= 12
                                                        ? 'bg-green-500'
                                                        : form.password.length >= 8
                                                            ? 'bg-yellow-500'
                                                            : 'bg-red-500'
                                                    : 'bg-muted'
                                            }`}
                                        />
                                    ))}
                                </div>
                                <span className="text-xs text-muted-foreground">
                                    {form.password.length >= 12 ? 'Fuerte' : form.password.length >= 8 ? 'Aceptable' : 'Débil'}
                                </span>
                            </div>
                        )}

                        <div className="pt-2">
                            <Button type="submit" className="bg-teal-600 hover:bg-teal-500 text-white border-0 rounded-xl px-6 gap-2 shadow-md shadow-teal-600/20 transition-all duration-200 hover:shadow-lg hover:shadow-teal-600/30 hover:-translate-y-px">
                                <Save className="size-4" />
                                Actualizar contraseña
                            </Button>
                        </div>
                    </form>
                </CardBody>
            </Card>
        </div>
    );
}

/* ───────────────────────── 2FA ───────────────────────── */
function TabDosPasos() {
    return (
        <div className="space-y-6">
            <SectionHeader
                icon={ShieldCheck}
                title="Autenticación de dos pasos"
                description="Añade una capa extra de seguridad a tu cuenta"
            />
            <Card>
                <CardBody className="space-y-5">
                    <div className="flex items-start gap-4 p-4 rounded-xl bg-muted/30 dark:bg-muted/10 border border-border/30">
                        <div className="rounded-xl bg-teal-500/10 dark:bg-teal-500/15 p-3 flex-shrink-0">
                            <ShieldCheck className="size-6 text-teal-600 dark:text-teal-400" />
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="font-semibold text-foreground text-sm">Autenticación por aplicación</p>
                            <p className="mt-1.5 text-xs text-muted-foreground leading-relaxed">
                                Usa una aplicación de autenticación como Google Authenticator o Authy para generar códigos de un solo uso.
                            </p>
                        </div>
                        <span className="flex items-center gap-1.5 text-xs font-medium text-amber-600 dark:text-amber-400 bg-amber-500/10 dark:bg-amber-500/15 rounded-full px-3 py-1.5 self-start flex-shrink-0">
                            <span className="size-1.5 rounded-full bg-amber-500 animate-pulse" />
                            No activado
                        </span>
                    </div>

                    <div className="rounded-xl border-2 border-dashed border-border/50 p-8 text-center">
                        <div className="mx-auto size-12 rounded-2xl bg-muted/50 dark:bg-muted/20 flex items-center justify-center mb-4">
                            <KeyRound className="size-6 text-muted-foreground/60" />
                        </div>
                        <p className="text-sm font-medium text-muted-foreground mb-1">Próximamente</p>
                        <p className="text-xs text-muted-foreground/70">
                            La autenticación de dos pasos estará disponible en una próxima actualización.
                        </p>
                    </div>
                </CardBody>
            </Card>
        </div>
    );
}

/* ───────────────────────── Sesiones ───────────────────────── */
function parseUserAgent(ua) {
    if (!ua) return { browser: 'Desconocido', os: 'Desconocido' };
    let browser = 'Navegador';
    let os = 'Sistema desconocido';

    if (/Chrome\//.test(ua) && !/Chromium/.test(ua) && !/Edg/.test(ua)) browser = 'Chrome';
    else if (/Firefox\//.test(ua)) browser = 'Firefox';
    else if (/Safari\//.test(ua) && !/Chrome/.test(ua)) browser = 'Safari';
    else if (/Edg\//.test(ua)) browser = 'Edge';
    else if (/OPR\//.test(ua)) browser = 'Opera';

    if (/Windows NT/.test(ua)) os = 'Windows';
    else if (/Mac OS X/.test(ua)) os = 'macOS';
    else if (/Linux/.test(ua)) os = 'Linux';
    else if (/Android/.test(ua)) os = 'Android';
    else if (/iPhone|iPad/.test(ua)) os = 'iOS';

    return { browser, os };
}

function TabSesiones({ sessions }) {
    const { errors } = usePage().props;
    const [password, setPassword] = useState('');

    function handleRevoke(e) {
        e.preventDefault();
        router.delete(route('settings.sessions.destroy'), { data: { password } }, {
            onSuccess: () => setPassword(''),
        });
    }

    return (
        <div className="space-y-6">
            <SectionHeader
                icon={Monitor}
                title="Sesiones activas"
                description="Gestiona y cierra tus sesiones activas en otros dispositivos"
            />
            <Card>
                <CardBody className="space-y-3">
                    {sessions.length === 0 && (
                        <div className="text-center py-8">
                            <Monitor className="size-10 text-muted-foreground/40 mx-auto mb-3" />
                            <p className="text-sm text-muted-foreground">No hay sesiones registradas.</p>
                        </div>
                    )}
                    {sessions.map(session => {
                        const { browser, os } = parseUserAgent(session.user_agent);
                        const isMobile = /Android|iPhone|iPad/.test(session.user_agent ?? '');
                        const DeviceIcon = isMobile ? Smartphone : Monitor;
                        const lastSeen = new Date(session.last_activity * 1000).toLocaleString('es-CO', {
                            dateStyle: 'medium', timeStyle: 'short',
                        });
                        return (
                            <div
                                key={session.id}
                                className={`flex items-center gap-4 rounded-xl p-4 transition-colors ${
                                    session.is_current
                                        ? 'bg-teal-500/5 dark:bg-teal-500/10 border border-teal-500/20'
                                        : 'bg-muted/20 dark:bg-muted/10 border border-border/30 hover:bg-muted/40 dark:hover:bg-muted/20'
                                }`}
                            >
                                <div className={`flex-shrink-0 rounded-xl p-2.5 ${
                                    session.is_current
                                        ? 'bg-teal-500/10 dark:bg-teal-500/15'
                                        : 'bg-muted/50 dark:bg-muted/20'
                                }`}>
                                    <DeviceIcon className={`size-5 ${
                                        session.is_current ? 'text-teal-600 dark:text-teal-400' : 'text-muted-foreground'
                                    }`} />
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-medium text-foreground flex items-center gap-2">
                                        {browser} en {os}
                                        {session.is_current && (
                                            <span className="inline-flex items-center gap-1 text-[11px] font-medium text-teal-600 dark:text-teal-400 bg-teal-500/10 dark:bg-teal-500/15 px-2 py-0.5 rounded-full">
                                                <span className="size-1.5 rounded-full bg-teal-500 animate-pulse" />
                                                Actual
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-xs text-muted-foreground flex items-center gap-1.5 mt-1">
                                        <Globe className="size-3 flex-shrink-0" /> {session.ip_address ?? '—'}
                                        <span className="mx-0.5 opacity-30">·</span>
                                        <Clock className="size-3 flex-shrink-0" /> {lastSeen}
                                    </p>
                                </div>
                                {session.is_current && (
                                    <CheckCircle2 className="size-5 text-teal-500 flex-shrink-0" />
                                )}
                            </div>
                        );
                    })}
                </CardBody>
            </Card>

            {sessions.length > 1 && (
                <Card className="border-red-500/20 dark:border-red-500/10">
                    <CardBody>
                        <form onSubmit={handleRevoke} className="space-y-4">
                            <div>
                                <p className="text-sm font-semibold text-foreground">Cerrar otras sesiones</p>
                                <p className="text-xs text-muted-foreground mt-1">
                                    Ingresa tu contraseña para cerrar todas las demás sesiones activas en otros dispositivos.
                                </p>
                            </div>
                            <Field label="Tu contraseña" icon={Lock} error={errors?.password}>
                                <Input
                                    type="password"
                                    value={password}
                                    onChange={e => setPassword(e.target.value)}
                                    placeholder="Confirma tu contraseña"
                                />
                            </Field>
                            <Button type="submit" variant="destructive" size="sm" className="rounded-xl gap-2">
                                <LogOut className="size-3.5" />
                                Cerrar otras sesiones
                            </Button>
                        </form>
                    </CardBody>
                </Card>
            )}
        </div>
    );
}

/* ───────────────────────── Apariencia ───────────────────────── */
function TabApariencia() {
    const { appearance, updateAppearance } = useAppearance();

    const options = [
        {
            value: 'light',
            label: 'Claro',
            desc: 'Fondo claro con textos oscuros',
            Icon: Sun,
            gradient: 'from-amber-400 to-orange-400',
        },
        {
            value: 'dark',
            label: 'Oscuro',
            desc: 'Fondo oscuro, menos fatiga visual',
            Icon: Moon,
            gradient: 'from-indigo-500 to-purple-500',
        },
        {
            value: 'system',
            label: 'Sistema',
            desc: 'Usa la configuración de tu SO',
            Icon: SunMoon,
            gradient: 'from-teal-400 to-cyan-400',
        },
    ];

    return (
        <div className="space-y-6">
            <SectionHeader
                icon={Palette}
                title="Apariencia"
                description="Personaliza cómo se ve la aplicación para ti"
            />
            <Card>
                <CardBody className="space-y-6">
                    <p className="text-sm font-medium text-foreground">Tema de color</p>
                    <div className="grid grid-cols-3 gap-4">
                        {options.map(({ value, label, desc, Icon, gradient }) => {
                            const isActive = appearance === value;
                            return (
                                <button
                                    key={value}
                                    onClick={() => updateAppearance(value)}
                                    className={`group relative flex flex-col items-center gap-3 rounded-2xl border-2 p-5 text-sm transition-all duration-300 ${
                                        isActive
                                            ? 'border-teal-500 bg-teal-500/5 dark:bg-teal-500/10 shadow-md shadow-teal-500/10'
                                            : 'border-border/50 hover:border-border hover:bg-muted/30 dark:hover:bg-muted/15'
                                    }`}
                                >
                                    <div className={`rounded-2xl p-3 transition-all duration-300 ${
                                        isActive
                                            ? `bg-gradient-to-br ${gradient} shadow-lg`
                                            : 'bg-muted/50 dark:bg-muted/20 group-hover:bg-muted/80 dark:group-hover:bg-muted/30'
                                    }`}>
                                        <Icon className={`size-6 transition-colors ${isActive ? 'text-white' : 'text-muted-foreground'}`} />
                                    </div>
                                    <div className="text-center">
                                        <span className={`font-semibold text-sm block ${isActive ? 'text-teal-600 dark:text-teal-400' : 'text-foreground'}`}>
                                            {label}
                                        </span>
                                        <span className="text-[11px] text-muted-foreground mt-0.5 block">{desc}</span>
                                    </div>
                                    {isActive && (
                                        <div className="absolute -top-2 -right-2 size-5 rounded-full bg-teal-500 flex items-center justify-center shadow-md">
                                            <CheckCircle2 className="size-3 text-white" />
                                        </div>
                                    )}
                                </button>
                            );
                        })}
                    </div>

                    {/* Preview */}
                    <div className="rounded-2xl border border-border/50 overflow-hidden">
                        <div className="bg-sidebar/80 px-4 py-3 border-b border-border/40 flex items-center gap-2">
                            <div className="flex gap-1.5">
                                <div className="size-3 rounded-full bg-red-400/70 dark:bg-red-500/50" />
                                <div className="size-3 rounded-full bg-yellow-400/70 dark:bg-yellow-500/50" />
                                <div className="size-3 rounded-full bg-green-400/70 dark:bg-green-500/50" />
                            </div>
                            <span className="ml-2 text-xs text-muted-foreground/70">Vista previa del tema</span>
                        </div>
                        <div className="bg-background/80 p-5 flex gap-4">
                            <div className="w-28 bg-sidebar/60 rounded-xl p-3 flex flex-col gap-2">
                                <div className="h-2.5 w-full bg-muted rounded-full" />
                                <div className="h-2.5 w-3/4 bg-teal-500/30 rounded-full" />
                                <div className="h-2.5 w-full bg-muted rounded-full" />
                                <div className="h-2.5 w-5/6 bg-muted rounded-full" />
                            </div>
                            <div className="flex-1 flex flex-col gap-2.5">
                                <div className="h-2.5 w-2/5 bg-foreground/15 rounded-full" />
                                <div className="h-2.5 w-full bg-muted/60 rounded-full" />
                                <div className="h-2.5 w-4/5 bg-muted/40 rounded-full" />
                                <div className="mt-2 h-8 w-24 bg-teal-500/20 rounded-lg" />
                            </div>
                        </div>
                    </div>
                </CardBody>
            </Card>
        </div>
    );
}

/* ───────────────────────── Tab WhatsApp (Estado y activaciones) ───────────────────────── */

const STATE_META = {
    ok:      { color: 'emerald', icon: CheckCircle2, label: 'OK' },
    pending: { color: 'amber',   icon: Clock,        label: 'Pendiente' },
    action:  { color: 'sky',     icon: AlertTriangle,label: 'Acción requerida' },
    manual:  { color: 'rose',    icon: XCircle,      label: 'Bloqueado / manual' },
    unknown: { color: 'zinc',    icon: AlertTriangle,label: 'Desconocido' },
};

function stateClasses(color) {
    return {
        emerald: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 ring-1 ring-inset ring-emerald-500/30',
        amber:   'bg-amber-500/15 text-amber-700 dark:text-amber-300 ring-1 ring-inset ring-amber-500/30',
        sky:     'bg-sky-500/15 text-sky-700 dark:text-sky-300 ring-1 ring-inset ring-sky-500/30',
        rose:    'bg-rose-500/15 text-rose-700 dark:text-rose-300 ring-1 ring-inset ring-rose-500/30',
        zinc:    'bg-zinc-500/15 text-zinc-700 dark:text-zinc-300 ring-1 ring-inset ring-zinc-500/30',
    }[color];
}

const WA_SUBTABS = [
    { id: 'readiness', label: 'Estado y activaciones', Icon: ListChecks },
    { id: 'profile',   label: 'Perfil del negocio',    Icon: Building2 },
    { id: 'numbers',   label: 'Números',               Icon: PhoneIcon },
];

function TabWhatsApp() {
    const [subTab, setSubTab] = useState('readiness');
    const [instances, setInstances] = useState([]);
    const [instanceId, setInstanceId] = useState(null);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState({});
    const [toast, setToast] = useState(null);
    const [requestCodeModal, setRequestCodeModal] = useState(false);
    const [verifyCodeModal, setVerifyCodeModal] = useState(false);

    useEffect(() => {
        axios.get('/api/settings/whatsapp/instances')
            .then(r => {
                setInstances(r.data ?? []);
                if (r.data?.length) setInstanceId(r.data[0].id);
            })
            .catch(() => setInstances([]));
    }, []);

    useEffect(() => {
        if (instanceId) load();
    }, [instanceId]);

    async function load() {
        setLoading(true);
        setError(null);
        try {
            const { data: res } = await axios.get('/api/settings/whatsapp/readiness', {
                params: { instance_id: instanceId },
            });
            setData(res);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudo verificar el estado en Meta.');
            setData(null);
        } finally {
            setLoading(false);
        }
    }

    function showToast(text, kind = 'success') {
        setToast({ text, kind });
        setTimeout(() => setToast(null), 4000);
    }

    async function doAction(checkId, payload = {}) {
        setBusy(prev => ({ ...prev, [checkId]: true }));
        try {
            let url;
            if (checkId === 'subscribe_webhook') url = '/api/settings/whatsapp/subscribe-webhook';
            else if (checkId === 'register_number') url = '/api/settings/whatsapp/register-number';
            else if (checkId === 'enable_insights') url = '/api/settings/whatsapp/enable-insights';
            else if (checkId === 'request_code') url = '/api/settings/whatsapp/request-code';
            else if (checkId === 'verify_code') url = '/api/settings/whatsapp/verify-code';
            else throw new Error('Acción no soportada');

            await axios.post(url, { instance_id: instanceId, ...payload });
            showToast('Activación completada correctamente.');
            await load();
            return true;
        } catch (err) {
            const meta = err?.response?.data?.error?.error;
            const msg = meta?.error_user_msg || meta?.message || err?.response?.data?.message || 'No se pudo completar la activación.';
            showToast(msg, 'error');
            return false;
        } finally {
            setBusy(prev => ({ ...prev, [checkId]: false }));
        }
    }

    const checks = data ? Object.values(data.checks) : [];
    const okCount = checks.filter(c => c.state === 'ok').length;
    const total = checks.length;
    const progress = total ? Math.round((okCount / total) * 100) : 0;

    return (
        <div className="flex flex-col gap-6">
            <SectionHeader
                title="WhatsApp Business"
                description="Estado de la cuenta, perfil público y números conectados."
                icon={MessageSquare}
            />

            {/* Instance picker */}
            <div className="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">
                <select
                    value={instanceId ?? ''}
                    onChange={e => setInstanceId(Number(e.target.value) || null)}
                    disabled={!instances.length}
                    className="flex-1 rounded-xl border border-border/70 bg-background/80 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/30"
                >
                    {!instances.length && <option>No hay instancias con WABA configurado</option>}
                    {instances.map(i => (
                        <option key={i.id} value={i.id}>{i.name} ({i.display_phone_number})</option>
                    ))}
                </select>
            </div>

            {/* Sub-tabs */}
            <div className="flex gap-1 border-b">
                {WA_SUBTABS.map(t => {
                    const active = subTab === t.id;
                    return (
                        <button
                            key={t.id}
                            onClick={() => setSubTab(t.id)}
                            className={`relative inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium transition-colors ${
                                active ? 'text-teal-600 dark:text-teal-400' : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            <t.Icon className="size-4" />
                            {t.label}
                            {active && <span className="absolute bottom-0 left-0 right-0 h-0.5 bg-teal-500 rounded-t" />}
                        </button>
                    );
                })}
            </div>

            {subTab === 'profile' && <BusinessProfilePanel instanceId={instanceId} setToast={setToast} />}
            {subTab === 'numbers' && <PhoneNumbersPanel instanceId={instanceId} />}
            {subTab !== 'readiness' ? null : (
            <>
            <div className="flex items-center gap-2">
                <Button onClick={load} disabled={loading || !instanceId} variant="outline" className="gap-2">
                    {loading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                    Verificar de nuevo
                </Button>
            </div>

            {error && (
                <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300 flex items-start gap-2">
                    <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                    <span>{error}</span>
                </div>
            )}

            {toast && (
                <div className={`rounded-xl border px-4 py-3 text-sm flex items-start gap-2 ${
                    toast.kind === 'error'
                        ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'
                        : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                }`}>
                    {toast.kind === 'error' ? <AlertTriangle className="size-4 mt-0.5 shrink-0" /> : <CheckCircle2 className="size-4 mt-0.5 shrink-0" />}
                    <span>{toast.text}</span>
                </div>
            )}

            {/* Progress card */}
            {data && (
                <div className="rounded-2xl border border-border/60 bg-gradient-to-br from-teal-500/5 via-card to-card p-5">
                    <div className="flex items-center justify-between mb-3">
                        <div>
                            <p className="text-sm font-semibold text-foreground">Preparación de la cuenta</p>
                            <p className="text-xs text-muted-foreground mt-0.5">
                                {okCount} de {total} chequeos completos · {progress}%
                            </p>
                        </div>
                        <BadgeCheck className={`size-7 ${progress === 100 ? 'text-emerald-500' : 'text-muted-foreground/50'}`} />
                    </div>
                    <div className="h-2 rounded-full bg-muted overflow-hidden">
                        <div
                            className="h-full rounded-full bg-gradient-to-r from-teal-500 to-emerald-500 transition-all"
                            style={{ width: `${progress}%` }}
                        />
                    </div>
                </div>
            )}

            {/* Checks list */}
            {loading && !data ? (
                <div className="space-y-3">
                    {Array.from({ length: 6 }).map((_, i) => (
                        <div key={i} className="rounded-2xl border border-border/60 bg-card/50 p-5 animate-pulse">
                            <div className="h-4 w-1/3 bg-muted rounded mb-2" />
                            <div className="h-3 w-2/3 bg-muted/60 rounded" />
                        </div>
                    ))}
                </div>
            ) : data ? (
                <div className="space-y-3">
                    {checks.map(check => (
                        <CheckRow
                            key={check.id}
                            check={check}
                            busy={busy}
                            onAction={doAction}
                            onRequestPin={() => doAction('register_number')}
                            onRequestCode={() => setRequestCodeModal(true)}
                            onVerifyCode={() => setVerifyCodeModal(true)}
                        />
                    ))}
                </div>
            ) : null}
            </>
            )}

            {requestCodeModal && (
                <RequestCodeModal
                    busy={!!busy.request_code}
                    onClose={() => setRequestCodeModal(false)}
                    onSubmit={async (payload) => {
                        const ok = await doAction('request_code', payload);
                        if (ok) {
                            setRequestCodeModal(false);
                            setVerifyCodeModal(true);
                        }
                    }}
                />
            )}

            {verifyCodeModal && (
                <VerifyCodeModal
                    busy={!!busy.verify_code}
                    onClose={() => setVerifyCodeModal(false)}
                    onResend={() => {
                        setVerifyCodeModal(false);
                        setRequestCodeModal(true);
                    }}
                    onSubmit={async (code) => {
                        const ok = await doAction('verify_code', { code });
                        if (ok) setVerifyCodeModal(false);
                    }}
                />
            )}
        </div>
    );
}

function CheckRow({ check, busy, onAction, onRequestPin, onRequestCode, onVerifyCode }) {
    const meta = STATE_META[check.state] ?? STATE_META.unknown;
    const Icon = meta.icon;

    return (
        <div className="rounded-2xl border border-border/60 bg-card/50 backdrop-blur-sm p-5 flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between hover:shadow-sm transition-shadow">
            <div className="flex items-start gap-3 flex-1 min-w-0">
                <div className={`size-10 rounded-xl flex items-center justify-center shrink-0 ${stateClasses(meta.color)}`}>
                    <Icon className="size-5" />
                </div>
                <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="font-semibold text-foreground">{check.title}</h3>
                        <span className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold ${stateClasses(meta.color)}`}>
                            {meta.label}
                        </span>
                        {check.raw_status && (
                            <span className="text-[10px] text-muted-foreground font-mono">{check.raw_status}</span>
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">{check.description}</p>
                    {check.extra?.verified_name && (
                        <p className="text-[11px] text-muted-foreground mt-1">
                            Nombre actual: <span className="font-medium text-foreground">{check.extra.verified_name}</span>
                        </p>
                    )}
                    {check.id === 'phone_registration' && (
                        <>
                            <p className="text-[11px] text-muted-foreground mt-1">
                                Estado en WhatsApp: <span className="font-medium text-foreground">{check.extra?.status ?? 'Desconocido'}</span>
                                {check.extra?.code_verification_status && (
                                    <> · PIN: <span className="font-medium text-foreground">{check.extra.code_verification_status}</span></>
                                )}
                            </p>
                            {check.extra?.quality_rating && (
                                <p className="text-[11px] text-muted-foreground mt-1">
                                    Calidad: <span className="font-medium text-foreground">{check.extra.quality_rating}</span>
                                    {check.extra.messaging_limit_tier && <> · Tier: <span className="font-medium text-foreground">{check.extra.messaging_limit_tier}</span></>}
                                </p>
                            )}
                        </>
                    )}
                    {check.id === 'webhook_subscription' && check.extra?.apps?.length > 0 && (
                        <p className="text-[11px] text-muted-foreground mt-1">
                            App suscrita: <span className="font-medium text-foreground">{check.extra.apps[0].name ?? check.extra.apps[0].id}</span>
                        </p>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-2 shrink-0">
                <CheckAction
                    check={check}
                    busy={busy}
                    onAction={onAction}
                    onRequestPin={onRequestPin}
                    onRequestCode={onRequestCode}
                    onVerifyCode={onVerifyCode}
                />
            </div>
        </div>
    );
}

function CheckAction({ check, busy, onAction, onRequestPin, onRequestCode, onVerifyCode }) {
    if (check.state === 'ok') {
        return <span className="text-xs text-emerald-600 dark:text-emerald-400 font-medium">Activado</span>;
    }

    const action = check.action_type;

    // Estado PENDING del número: mostramos "Esperando..." + acción secundaria de reintento
    if (check.state === 'pending' && check.id === 'phone_registration') {
        return (
            <div className="flex items-center gap-2">
                <span className="text-xs text-amber-600 dark:text-amber-400 font-medium">Esperando a WhatsApp</span>
                {action === 'register_number' && (
                    <Button size="sm" variant="outline" onClick={onRequestPin} disabled={!!busy.register_number} className="gap-1.5 h-7 text-xs">
                        {busy.register_number ? <Loader2 className="size-3 animate-spin" /> : <RefreshCw className="size-3" />}
                        Reintentar
                    </Button>
                )}
                {action === 'verify_code' && (
                    <Button size="sm" variant="outline" onClick={onVerifyCode} disabled={!!busy.verify_code} className="gap-1.5 h-7 text-xs">
                        {busy.verify_code ? <Loader2 className="size-3 animate-spin" /> : <BadgeCheck className="size-3" />}
                        Ingresar código
                    </Button>
                )}
            </div>
        );
    }

    if (action === 'subscribe_webhook') {
        return (
            <Button onClick={() => onAction('subscribe_webhook')} disabled={!!busy.subscribe_webhook} className="gap-2">
                {busy.subscribe_webhook ? <Loader2 className="size-4 animate-spin" /> : <Webhook className="size-4" />}
                Suscribir webhook
            </Button>
        );
    }
    if (action === 'enable_insights') {
        return (
            <Button onClick={() => onAction('enable_insights')} disabled={!!busy.enable_insights} className="gap-2">
                {busy.enable_insights ? <Loader2 className="size-4 animate-spin" /> : <BarChart3 className="size-4" />}
                Activar analítica
            </Button>
        );
    }
    if (action === 'register_number') {
        return (
            <Button onClick={onRequestPin} disabled={!!busy.register_number} className="gap-2">
                {busy.register_number ? <Loader2 className="size-4 animate-spin" /> : <KeyRound className="size-4" />}
                Registrar número
            </Button>
        );
    }
    if (action === 'request_code') {
        return (
            <Button onClick={onRequestCode} disabled={!!busy.request_code} className="gap-2">
                {busy.request_code ? <Loader2 className="size-4 animate-spin" /> : <MessageCircle className="size-4" />}
                Verificar número
            </Button>
        );
    }
    if (action === 'verify_code') {
        return (
            <Button onClick={onVerifyCode} disabled={!!busy.verify_code} className="gap-2">
                {busy.verify_code ? <Loader2 className="size-4 animate-spin" /> : <BadgeCheck className="size-4" />}
                Ingresar código
            </Button>
        );
    }

    // manual
    if (check.manual_url) {
        return (
            <a
                href={check.manual_url}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-2 rounded-lg border border-border/70 bg-background hover:bg-muted px-3 py-1.5 text-xs font-medium text-foreground transition-colors"
            >
                <ExternalLink className="size-3.5" />
                Gestionar en Meta
            </a>
        );
    }
    return <span className="text-xs text-muted-foreground italic">Manual</span>;
}

function RequestCodeModal({ onClose, onSubmit, busy }) {
    const [codeMethod, setCodeMethod] = useState('SMS');
    const [language, setLanguage] = useState('es');

    function handleSubmit(e) {
        e.preventDefault();
        onSubmit({ code_method: codeMethod, language });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-md rounded-2xl border bg-card shadow-2xl p-6" onClick={e => e.stopPropagation()}>
                <div className="mb-5 flex items-start gap-3">
                    <div className="size-10 rounded-xl bg-sky-500/15 text-sky-600 dark:text-sky-400 flex items-center justify-center shrink-0">
                        <MessageCircle className="size-5" />
                    </div>
                    <div>
                        <h3 className="font-semibold text-foreground">Verificar número (paso 1 de 2)</h3>
                        <p className="text-xs text-muted-foreground mt-1">
                            Meta enviará un código de 6 dígitos al número para confirmar que tienes acceso.
                            Si el número estaba usándose en la app WhatsApp Business, debes <strong>cerrar sesión en esa app</strong> antes de continuar.
                        </p>
                    </div>
                </div>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <label className="text-xs font-medium text-foreground mb-2 block">Cómo enviar el código</label>
                        <div className="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                onClick={() => setCodeMethod('SMS')}
                                className={`flex items-center gap-2 rounded-lg border px-3 py-2.5 text-sm transition-colors ${
                                    codeMethod === 'SMS'
                                        ? 'border-sky-500 bg-sky-500/10 text-foreground'
                                        : 'border-border/70 bg-background text-muted-foreground hover:bg-muted'
                                }`}
                            >
                                <MessageCircle className="size-4" />
                                SMS
                            </button>
                            <button
                                type="button"
                                onClick={() => setCodeMethod('VOICE')}
                                className={`flex items-center gap-2 rounded-lg border px-3 py-2.5 text-sm transition-colors ${
                                    codeMethod === 'VOICE'
                                        ? 'border-sky-500 bg-sky-500/10 text-foreground'
                                        : 'border-border/70 bg-background text-muted-foreground hover:bg-muted'
                                }`}
                            >
                                <PhoneCall className="size-4" />
                                Llamada de voz
                            </button>
                        </div>
                    </div>
                    <div>
                        <label className="text-xs font-medium text-foreground">Idioma del código</label>
                        <select
                            value={language}
                            onChange={e => setLanguage(e.target.value)}
                            className="mt-1 w-full rounded-lg border border-border/70 bg-background px-3 py-2 text-sm"
                        >
                            <option value="es">Español</option>
                            <option value="en">Inglés</option>
                            <option value="pt_BR">Portugués (Brasil)</option>
                        </select>
                    </div>
                    <div className="flex gap-2 pt-2">
                        <Button type="submit" disabled={busy} className="flex-1 gap-2">
                            {busy && <Loader2 className="size-4 animate-spin" />}
                            Enviar código
                        </Button>
                        <Button type="button" variant="outline" onClick={onClose} disabled={busy}>Cancelar</Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function VerifyCodeModal({ onClose, onSubmit, onResend, busy }) {
    const [code, setCode] = useState('');
    const [error, setError] = useState(null);

    function handleSubmit(e) {
        e.preventDefault();
        setError(null);
        const clean = code.replace(/\D/g, '');
        if (clean.length !== 6) {
            setError('El código debe tener 6 dígitos.');
            return;
        }
        onSubmit(clean);
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-md rounded-2xl border bg-card shadow-2xl p-6" onClick={e => e.stopPropagation()}>
                <div className="mb-5 flex items-start gap-3">
                    <div className="size-10 rounded-xl bg-sky-500/15 text-sky-600 dark:text-sky-400 flex items-center justify-center shrink-0">
                        <BadgeCheck className="size-5" />
                    </div>
                    <div>
                        <h3 className="font-semibold text-foreground">Ingresar código (paso 2 de 2)</h3>
                        <p className="text-xs text-muted-foreground mt-1">
                            Escribe el código de 6 dígitos que WhatsApp envió al número. Una vez verificado podrás registrarlo con tu PIN.
                        </p>
                    </div>
                </div>
                <form onSubmit={handleSubmit} className="space-y-3">
                    <div>
                        <label className="text-xs font-medium text-foreground">Código recibido</label>
                        <input
                            type="text"
                            inputMode="numeric"
                            maxLength={7}
                            value={code}
                            onChange={e => setCode(e.target.value.replace(/[^\d-]/g, '').slice(0, 7))}
                            placeholder="123-456"
                            autoFocus
                            className="mt-1 w-full rounded-lg border border-border/70 bg-background px-3 py-2 text-sm font-mono tracking-widest text-center"
                        />
                    </div>
                    {error && <p className="text-xs text-rose-600 dark:text-rose-400">{error}</p>}
                    <div className="flex items-center justify-between text-xs">
                        <button type="button" onClick={onResend} disabled={busy} className="text-sky-600 dark:text-sky-400 hover:underline disabled:opacity-50">
                            Reenviar código
                        </button>
                    </div>
                    <div className="flex gap-2 pt-2">
                        <Button type="submit" disabled={busy} className="flex-1 gap-2">
                            {busy && <Loader2 className="size-4 animate-spin" />}
                            Verificar
                        </Button>
                        <Button type="button" variant="outline" onClick={onClose} disabled={busy}>Cancelar</Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

/* ───────────────────────── Sub-paneles WhatsApp ───────────────────────── */

const VERTICAL_OPTIONS = [
    { value: '', label: 'Sin definir' },
    { value: 'OTHER', label: 'Otro' },
    { value: 'AUTO', label: 'Automotor' },
    { value: 'BEAUTY', label: 'Belleza, spa y peluquería' },
    { value: 'APPAREL', label: 'Ropa y moda' },
    { value: 'EDU', label: 'Educación' },
    { value: 'ENTERTAIN', label: 'Entretenimiento' },
    { value: 'EVENT_PLAN', label: 'Eventos' },
    { value: 'FINANCE', label: 'Finanzas y banca' },
    { value: 'GROCERY', label: 'Supermercado' },
    { value: 'GOVT', label: 'Servicios públicos' },
    { value: 'HOTEL', label: 'Hotelería' },
    { value: 'HEALTH', label: 'Salud' },
    { value: 'NONPROFIT', label: 'Sin ánimo de lucro' },
    { value: 'PROF_SERVICES', label: 'Servicios profesionales' },
    { value: 'RETAIL', label: 'Retail' },
    { value: 'TRAVEL', label: 'Viajes' },
    { value: 'RESTAURANT', label: 'Restaurantes' },
];

// Estado del "display name" (verified_name) según Meta. Es solo lectura por API.
const NAME_STATUS_META = {
    APPROVED: { label: 'Aprobado', cls: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 ring-1 ring-inset ring-emerald-500/30' },
    AVAILABLE_WITHOUT_REVIEW: { label: 'Disponible', cls: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 ring-1 ring-inset ring-emerald-500/30' },
    PENDING_REVIEW: { label: 'En revisión', cls: 'bg-amber-500/15 text-amber-600 dark:text-amber-400 ring-1 ring-inset ring-amber-500/30' },
    PENDING: { label: 'En revisión', cls: 'bg-amber-500/15 text-amber-600 dark:text-amber-400 ring-1 ring-inset ring-amber-500/30' },
    DECLINED: { label: 'Rechazado', cls: 'bg-rose-500/15 text-rose-600 dark:text-rose-400 ring-1 ring-inset ring-rose-500/30' },
    EXPIRED: { label: 'Expirado', cls: 'bg-rose-500/15 text-rose-600 dark:text-rose-400 ring-1 ring-inset ring-rose-500/30' },
    NONE: { label: 'Sin nombre', cls: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400 ring-1 ring-inset ring-zinc-500/30' },
};

function BusinessProfilePanel({ instanceId, setToast }) {
    const [profile, setProfile] = useState(null);
    const [nameInfo, setNameInfo] = useState(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [errors, setErrors] = useState({});

    useEffect(() => { if (instanceId) load(); }, [instanceId]);

    async function load() {
        setLoading(true);
        setError(null);
        try {
            const { data } = await axios.get('/api/settings/whatsapp/profile', { params: { instance_id: instanceId } });
            const p = data.profile ?? {};
            setProfile({
                about: p.about ?? '',
                address: p.address ?? '',
                description: p.description ?? '',
                email: p.email ?? '',
                websites: p.websites?.length ? p.websites : [''],
                vertical: p.vertical ?? '',
                profile_picture_url: p.profile_picture_url ?? null,
            });
            setNameInfo(data.name ?? null);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudo cargar el perfil.');
            setProfile(null);
        } finally {
            setLoading(false);
        }
    }

    async function save(e) {
        e.preventDefault();
        setErrors({});
        setSaving(true);
        try {
            await axios.post('/api/settings/whatsapp/profile', {
                instance_id: instanceId,
                about: profile.about,
                address: profile.address,
                description: profile.description,
                email: profile.email,
                websites: profile.websites.filter(Boolean),
                vertical: profile.vertical || null,
            });
            setToast?.({ text: 'Perfil actualizado correctamente.', kind: 'success' });
            await load();
        } catch (err) {
            if (err?.response?.status === 422) {
                setErrors(err.response.data?.errors ?? {});
                setToast?.({ text: 'Revisa los campos del formulario.', kind: 'error' });
            } else {
                const meta = err?.response?.data?.error?.error;
                setToast?.({ text: meta?.error_user_msg || meta?.message || 'No se pudo guardar.', kind: 'error' });
            }
        } finally {
            setSaving(false);
        }
    }

    async function uploadPhoto(e) {
        const file = e.target.files?.[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) {
            setToast?.({ text: 'La imagen no puede pesar más de 5 MB.', kind: 'error' });
            return;
        }
        setSaving(true);
        try {
            const fd = new FormData();
            fd.append('instance_id', instanceId);
            fd.append('photo', file);
            await axios.post('/api/settings/whatsapp/profile/photo', fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            setToast?.({ text: 'Foto de perfil actualizada.', kind: 'success' });
            await load();
        } catch (err) {
            const meta = err?.response?.data?.error?.error;
            setToast?.({ text: meta?.error_user_msg || meta?.message || 'No se pudo subir la foto.', kind: 'error' });
        } finally {
            setSaving(false);
            e.target.value = '';
        }
    }

    if (!instanceId) {
        return <p className="text-sm text-muted-foreground">Selecciona una instancia.</p>;
    }
    if (loading && !profile) {
        return <div className="flex items-center gap-2 text-sm text-muted-foreground py-10 justify-center"><Loader2 className="size-4 animate-spin" /> Cargando perfil...</div>;
    }
    if (error) {
        return <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">{error}</div>;
    }
    if (!profile) return null;

    const nameStatus = nameInfo?.name_status ? (NAME_STATUS_META[nameInfo.name_status] ?? { label: nameInfo.name_status, cls: NAME_STATUS_META.NONE.cls }) : null;

    return (
        <form onSubmit={save} className="space-y-6">
            {/* Nombre para mostrar (solo lectura) */}
            <div className="rounded-2xl border border-border/60 bg-card/50 p-5">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h3 className="font-semibold text-foreground">Nombre para mostrar</h3>
                        <p className="text-lg font-bold text-foreground mt-1 truncate">{nameInfo?.display_name || 'Sin nombre aprobado'}</p>
                        {nameInfo?.display_phone_number && (
                            <p className="text-xs text-muted-foreground mt-0.5">{nameInfo.display_phone_number}</p>
                        )}
                    </div>
                    {nameStatus && (
                        <span className={`shrink-0 text-[11px] font-bold px-2.5 py-1 rounded-full ${nameStatus.cls}`}>
                            {nameStatus.label}
                        </span>
                    )}
                </div>
                <p className="text-[11px] text-muted-foreground mt-3 leading-relaxed">
                    El nombre para mostrar no se edita aquí: se solicita en <span className="font-medium text-foreground">WhatsApp Manager</span>, Meta lo revisa y luego debes re-registrar el número en la pestaña <span className="font-medium text-foreground">Estado y activaciones</span>. Permite hasta 10 cambios cada 30 días.
                </p>
            </div>

            {/* Photo */}
            <div className="rounded-2xl border border-border/60 bg-card/50 p-5">
                <div className="flex items-center gap-5">
                    <div className="relative">
                        <div className="size-24 rounded-2xl bg-muted overflow-hidden ring-2 ring-border/40 flex items-center justify-center">
                            {profile.profile_picture_url
                                ? <img src={profile.profile_picture_url} alt="" className="w-full h-full object-cover" />
                                : <ImageIcon className="size-8 text-muted-foreground/40" />}
                        </div>
                    </div>
                    <div className="flex-1">
                        <h3 className="font-semibold text-foreground">Foto de perfil</h3>
                        <p className="text-xs text-muted-foreground mt-1">Cuadrada · mínimo 192×192 px · hasta 5 MB · JPG, PNG o WebP.</p>
                        <label className="inline-flex items-center gap-2 mt-3 rounded-lg border border-border/70 bg-background hover:bg-muted px-3 py-1.5 text-xs font-medium cursor-pointer transition-colors">
                            <Camera className="size-3.5" />
                            Cambiar foto
                            <input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={uploadPhoto} disabled={saving} />
                        </label>
                    </div>
                </div>
            </div>

            <div className="rounded-2xl border border-border/60 bg-card/50 p-5 space-y-4">
                <h3 className="font-semibold text-foreground">Información pública</h3>

                <Field label="Acerca de" icon={MessageSquare} error={errors.about?.[0]}>
                    <Input
                        value={profile.about}
                        onChange={e => setProfile(p => ({ ...p, about: e.target.value }))}
                        placeholder="Atención al cliente de lunes a viernes"
                        maxLength={139}
                    />
                    <div className="text-[10px] text-muted-foreground text-right">{profile.about.length}/139</div>
                </Field>

                <Field label="Descripción del negocio" icon={FileText} error={errors.description?.[0]}>
                    <textarea
                        value={profile.description}
                        onChange={e => setProfile(p => ({ ...p, description: e.target.value }))}
                        placeholder="Describe brevemente a qué se dedica tu empresa..."
                        maxLength={512}
                        rows={3}
                        className="w-full rounded-xl border border-border/70 bg-background/80 px-4 py-2.5 text-sm placeholder:text-muted-foreground/60 focus:outline-none focus:ring-2 focus:ring-teal-500/30 resize-y"
                    />
                    <div className="text-[10px] text-muted-foreground text-right">{profile.description.length}/512</div>
                </Field>

                <Field label="Dirección" icon={MapPin} error={errors.address?.[0]}>
                    <Input
                        value={profile.address}
                        onChange={e => setProfile(p => ({ ...p, address: e.target.value }))}
                        placeholder="Calle 123 #45-67, Bogotá"
                        maxLength={256}
                    />
                </Field>

                <Field label="Email" icon={Mail} error={errors.email?.[0]}>
                    <Input
                        type="email"
                        value={profile.email}
                        onChange={e => setProfile(p => ({ ...p, email: e.target.value }))}
                        placeholder="contacto@empresa.com"
                        maxLength={128}
                    />
                </Field>

                <Field label="Sitios web (hasta 2)" icon={Globe} error={errors['websites.0']?.[0] ?? errors['websites.1']?.[0]}>
                    {[0, 1].map(i => (
                        <Input
                            key={i}
                            value={profile.websites[i] ?? ''}
                            onChange={e => setProfile(p => {
                                const w = [...p.websites];
                                w[i] = e.target.value;
                                return { ...p, websites: w };
                            })}
                            placeholder={i === 0 ? 'https://www.empresa.com' : 'https://shop.empresa.com (opcional)'}
                            className="mb-2"
                        />
                    ))}
                </Field>

                <Field label="Categoría" icon={Building2} error={errors.vertical?.[0]}>
                    <select
                        value={profile.vertical}
                        onChange={e => setProfile(p => ({ ...p, vertical: e.target.value }))}
                        className="w-full rounded-xl border border-border/70 bg-background/80 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/30"
                    >
                        {VERTICAL_OPTIONS.map(v => (
                            <option key={v.value} value={v.value}>{v.label}</option>
                        ))}
                    </select>
                </Field>
            </div>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={load} disabled={loading || saving}>
                    <RefreshCw className="size-4" />
                </Button>
                <Button type="submit" disabled={saving} className="gap-2">
                    {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
                    Guardar cambios
                </Button>
            </div>
        </form>
    );
}

function PhoneNumbersPanel({ instanceId }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => { if (instanceId) load(); }, [instanceId]);

    async function load() {
        setLoading(true);
        setError(null);
        try {
            const { data } = await axios.get('/api/settings/whatsapp/phone-numbers', { params: { instance_id: instanceId } });
            setData(data);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudieron cargar los números.');
            setData(null);
        } finally {
            setLoading(false);
        }
    }

    if (!instanceId) return <p className="text-sm text-muted-foreground">Selecciona una instancia.</p>;

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">Todos los números de la WhatsApp Business Account.</p>
                <Button onClick={load} disabled={loading} variant="outline" size="sm" className="gap-2">
                    {loading ? <Loader2 className="size-3.5 animate-spin" /> : <RefreshCw className="size-3.5" />}
                    Refrescar
                </Button>
            </div>

            {error && (
                <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">{error}</div>
            )}

            {data && (
                <div className="space-y-2">
                    {(data.phone_numbers ?? []).map(p => {
                        const isCurrent = String(p.id) === String(data.current_phone_number_id);
                        return (
                            <div key={p.id} className={`rounded-2xl border p-4 ${isCurrent ? 'border-teal-500/40 bg-teal-500/5' : 'border-border/60 bg-card/50'}`}>
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className="font-mono font-semibold text-foreground">{p.display_phone_number}</span>
                                            {isCurrent && (
                                                <span className="inline-flex items-center gap-1 rounded-md bg-teal-500/15 text-teal-700 dark:text-teal-300 px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ring-teal-500/30">
                                                    <CheckCircle2 className="size-3" /> Actual
                                                </span>
                                            )}
                                            {p.quality_rating && (
                                                <QualityPill rating={p.quality_rating} />
                                            )}
                                        </div>
                                        {p.verified_name && (
                                            <div className="text-xs text-foreground mt-0.5 font-medium">{p.verified_name}</div>
                                        )}
                                        <div className="flex items-center gap-3 mt-1.5 text-[11px] text-muted-foreground flex-wrap">
                                            <span>ID: <span className="font-mono">{p.id}</span></span>
                                            {p.status && <span>Estado: <span className="font-medium text-foreground">{p.status}</span></span>}
                                            {p.code_verification_status && <span>Verificación: <span className="font-medium text-foreground">{p.code_verification_status}</span></span>}
                                            {p.platform_type && <span>Plataforma: <span className="font-medium text-foreground">{p.platform_type}</span></span>}
                                            {p.messaging_limit_tier && <span>Tier: <span className="font-medium text-foreground">{p.messaging_limit_tier}</span></span>}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                    {data.phone_numbers?.length === 0 && (
                        <div className="rounded-2xl border border-dashed py-10 text-center text-sm text-muted-foreground">
                            No hay números registrados en esta WABA.
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function QualityPill({ rating }) {
    const map = {
        GREEN: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 ring-emerald-500/30',
        YELLOW: 'bg-amber-500/15 text-amber-700 dark:text-amber-300 ring-amber-500/30',
        RED: 'bg-rose-500/15 text-rose-700 dark:text-rose-300 ring-rose-500/30',
        UNKNOWN: 'bg-zinc-500/15 text-zinc-700 dark:text-zinc-300 ring-zinc-500/30',
    };
    return (
        <span className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${map[rating] ?? map.UNKNOWN}`}>
            Calidad: {rating}
        </span>
    );
}

/* ───────────────────────── Horarios ───────────────────────── */

const DAY_OPTIONS = [
    { value: 1, label: 'Lun' },
    { value: 2, label: 'Mar' },
    { value: 3, label: 'Mié' },
    { value: 4, label: 'Jue' },
    { value: 5, label: 'Vie' },
    { value: 6, label: 'Sáb' },
    { value: 0, label: 'Dom' },
];

const EMPTY_HOUR = {
    id: null,
    name: 'Horario laboral',
    instance_id: '',
    active: true,
    timezone: 'America/Bogota',
    start_time: '08:00',
    end_time: '20:00',
    days_of_week: [1, 2, 3, 4, 5],
    out_of_hours_message: 'Hola {name}, gracias por escribirnos. Nuestro horario de atención es de {start} a {end}. Te responderemos lo antes posible.',
    cooldown_minutes: 60,
};

function to12h(hhmm) {
    if (!hhmm) return '';
    const [h, m] = hhmm.slice(0, 5).split(':').map(Number);
    const period = h < 12 ? 'a.m.' : 'p.m.';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m).padStart(2, '0')} ${period}`;
}

// Describe en lenguaje claro qué hará el horario, para evitar que se cargue invertido (la trampa de a.m./p.m.).
function describeSchedule(start, end) {
    if (!start || !end) return null;
    const s = start.slice(0, 5);
    const e = end.slice(0, 5);
    if (s === e) {
        return { tone: 'info', text: 'Abierto las 24 horas: nunca se enviará el mensaje fuera de horario.' };
    }
    if (s < e) {
        return { tone: 'ok', text: `Abierto de ${to12h(s)} a ${to12h(e)}. Fuera de ese rango se enviará el mensaje automático.` };
    }
    return { tone: 'warn', text: `Atención: este horario cruza la medianoche. Se interpreta como abierto desde las ${to12h(s)} hasta las ${to12h(e)} del día siguiente. Si tu negocio atiende de día, seguramente invertiste Inicio y Fin (revisa a.m./p.m.).` };
}

function TabHorarios() {
    const [items, setItems] = useState([]);
    const [instances, setInstances] = useState([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(null);
    const [errors, setErrors] = useState({});
    const [toast, setToast] = useState(null);

    function showToast(text, kind = 'success') {
        setToast({ text, kind });
        setTimeout(() => setToast(null), 4000);
    }

    async function load() {
        setLoading(true);
        setError(null);
        try {
            const { data } = await axios.get('/api/business-hours');
            setItems(data.business_hours ?? []);
            setInstances(data.instances ?? []);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudieron cargar los horarios.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => { load(); }, []);

    function openCreate() {
        setEditing({ ...EMPTY_HOUR });
        setErrors({});
    }

    function openEdit(item) {
        setEditing({
            id: item.id,
            name: item.name ?? '',
            instance_id: item.instance_id ?? '',
            active: !!item.active,
            timezone: item.timezone ?? 'America/Bogota',
            start_time: (item.start_time ?? '08:00:00').slice(0, 5),
            end_time: (item.end_time ?? '20:00:00').slice(0, 5),
            days_of_week: Array.isArray(item.days_of_week) ? item.days_of_week : [1, 2, 3, 4, 5],
            out_of_hours_message: item.out_of_hours_message ?? '',
            cooldown_minutes: item.cooldown_minutes ?? 60,
        });
        setErrors({});
    }

    async function save() {
        setSaving(true);
        setErrors({});
        const payload = {
            ...editing,
            instance_id: editing.instance_id || null,
        };
        try {
            if (editing.id) {
                await axios.put(`/api/business-hours/${editing.id}`, payload);
                showToast('Horario actualizado.');
            } else {
                await axios.post('/api/business-hours', payload);
                showToast('Horario creado.');
            }
            setEditing(null);
            await load();
        } catch (err) {
            if (err?.response?.status === 422) {
                setErrors(err.response.data?.errors ?? {});
                showToast('Revisa los campos del formulario.', 'error');
            } else {
                showToast(err?.response?.data?.message ?? 'No se pudo guardar.', 'error');
            }
        } finally {
            setSaving(false);
        }
    }

    async function remove(id) {
        if (!confirm('¿Eliminar este horario?')) return;
        try {
            await axios.delete(`/api/business-hours/${id}`);
            showToast('Horario eliminado.');
            await load();
        } catch (err) {
            showToast(err?.response?.data?.message ?? 'No se pudo eliminar.', 'error');
        }
    }

    function toggleDay(day) {
        const set = new Set(editing.days_of_week ?? []);
        if (set.has(day)) set.delete(day); else set.add(day);
        setEditing(e => ({ ...e, days_of_week: Array.from(set).sort() }));
    }

    return (
        <div className="space-y-6">
            <SectionHeader
                icon={CalendarClock}
                title="Horarios de atención"
                description="Configura el horario en el que respondes. Fuera de este rango se enviará automáticamente el mensaje que definas."
            />

            {toast && (
                <div className={`rounded-xl border px-4 py-3 text-sm flex items-start gap-2 ${
                    toast.kind === 'error'
                        ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'
                        : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                }`}>
                    {toast.kind === 'error' ? <AlertTriangle className="size-4 mt-0.5 shrink-0" /> : <CheckCircle2 className="size-4 mt-0.5 shrink-0" />}
                    <span>{toast.text}</span>
                </div>
            )}

            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    {items.length === 0 ? 'Aún no tienes horarios configurados.' : `${items.length} horario(s) configurado(s).`}
                </p>
                <Button onClick={openCreate} className="bg-teal-600 hover:bg-teal-500 text-white rounded-xl gap-2">
                    <Plus className="size-4" />
                    Nuevo horario
                </Button>
            </div>

            {error && (
                <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                    {error}
                </div>
            )}

            {loading ? (
                <div className="flex items-center gap-2 text-sm text-muted-foreground py-10 justify-center">
                    <Loader2 className="size-4 animate-spin" /> Cargando horarios...
                </div>
            ) : (
                <div className="space-y-3">
                    {items.map(item => {
                        const days = Array.isArray(item.days_of_week) && item.days_of_week.length
                            ? item.days_of_week.map(d => DAY_OPTIONS.find(o => o.value === d)?.label).filter(Boolean).join(', ')
                            : 'Todos los días';
                        return (
                            <div key={item.id} className="rounded-2xl border border-border/60 bg-card/50 p-5 flex flex-col sm:flex-row gap-4 sm:items-center">
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <h3 className="font-semibold text-foreground">{item.name}</h3>
                                        {item.active ? (
                                            <span className="inline-flex items-center rounded-md bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 ring-1 ring-inset ring-emerald-500/30 px-1.5 py-0.5 text-[10px] font-semibold">Activo</span>
                                        ) : (
                                            <span className="inline-flex items-center rounded-md bg-zinc-500/15 text-zinc-700 dark:text-zinc-300 ring-1 ring-inset ring-zinc-500/30 px-1.5 py-0.5 text-[10px] font-semibold">Pausado</span>
                                        )}
                                        {item.instance ? (
                                            <span className="inline-flex items-center rounded-md bg-sky-500/15 text-sky-700 dark:text-sky-300 ring-1 ring-inset ring-sky-500/30 px-1.5 py-0.5 text-[10px] font-semibold">{item.instance.name}</span>
                                        ) : (
                                            <span className="inline-flex items-center rounded-md bg-muted text-muted-foreground ring-1 ring-inset ring-border/50 px-1.5 py-0.5 text-[10px] font-semibold">Todas las instancias</span>
                                        )}
                                    </div>
                                    <p className="text-xs text-muted-foreground mt-1.5">
                                        {(item.start_time ?? '').slice(0,5)} – {(item.end_time ?? '').slice(0,5)} · {days} · {item.timezone}
                                    </p>
                                    <p className="text-xs text-muted-foreground/80 mt-1 line-clamp-2">{item.out_of_hours_message}</p>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    <Button onClick={() => openEdit(item)} variant="outline" size="sm" className="gap-1.5 rounded-lg">
                                        <Pencil className="size-3.5" />
                                        Editar
                                    </Button>
                                    <Button onClick={() => remove(item.id)} variant="outline" size="sm" className="gap-1.5 rounded-lg text-rose-600 dark:text-rose-400 hover:text-rose-700">
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {editing && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={() => !saving && setEditing(null)}>
                    <div className="w-full max-w-xl rounded-2xl border bg-card shadow-2xl p-6 max-h-[90vh] overflow-y-auto" onClick={e => e.stopPropagation()}>
                        <div className="flex items-center gap-3 mb-5">
                            <div className="size-10 rounded-xl bg-teal-500/15 text-teal-600 dark:text-teal-400 flex items-center justify-center shrink-0">
                                <CalendarClock className="size-5" />
                            </div>
                            <h3 className="font-semibold text-foreground text-lg">
                                {editing.id ? 'Editar horario' : 'Nuevo horario'}
                            </h3>
                        </div>

                        <div className="space-y-4">
                            <Field label="Nombre" error={errors.name?.[0]}>
                                <Input
                                    value={editing.name}
                                    onChange={e => setEditing(s => ({ ...s, name: e.target.value }))}
                                    placeholder="Horario laboral"
                                />
                            </Field>

                            <Field label="Instancia" icon={MessageSquare} error={errors.instance_id?.[0]}>
                                <select
                                    value={editing.instance_id ?? ''}
                                    onChange={e => setEditing(s => ({ ...s, instance_id: e.target.value }))}
                                    className="w-full rounded-xl border border-border/70 bg-background/80 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/30"
                                >
                                    <option value="">Todas las instancias</option>
                                    {instances.map(i => (
                                        <option key={i.id} value={i.id}>{i.name}</option>
                                    ))}
                                </select>
                            </Field>

                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Inicio" icon={Clock} error={errors.start_time?.[0]}>
                                    <Input
                                        type="time"
                                        value={editing.start_time}
                                        onChange={e => setEditing(s => ({ ...s, start_time: e.target.value }))}
                                    />
                                </Field>
                                <Field label="Fin" icon={Clock} error={errors.end_time?.[0]}>
                                    <Input
                                        type="time"
                                        value={editing.end_time}
                                        onChange={e => setEditing(s => ({ ...s, end_time: e.target.value }))}
                                    />
                                </Field>
                            </div>

                            {(() => {
                                const d = describeSchedule(editing.start_time, editing.end_time);
                                if (!d) return null;
                                const styles = {
                                    ok: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
                                    warn: 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300',
                                    info: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
                                };
                                return (
                                    <div className={`-mt-2 rounded-lg border px-3 py-2 text-[12px] flex items-start gap-2 ${styles[d.tone]}`}>
                                        {d.tone === 'warn'
                                            ? <AlertTriangle className="size-3.5 mt-0.5 shrink-0" />
                                            : <Clock className="size-3.5 mt-0.5 shrink-0" />}
                                        <span>{d.text}</span>
                                    </div>
                                );
                            })()}

                            <Field label="Días" error={errors.days_of_week?.[0]}>
                                <div className="flex gap-1.5 flex-wrap">
                                    {DAY_OPTIONS.map(d => {
                                        const isActive = editing.days_of_week?.includes(d.value);
                                        return (
                                            <button
                                                type="button"
                                                key={d.value}
                                                onClick={() => toggleDay(d.value)}
                                                className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-all ${
                                                    isActive
                                                        ? 'bg-teal-500/15 border-teal-500/40 text-teal-700 dark:text-teal-300'
                                                        : 'bg-background border-border/60 text-muted-foreground hover:border-border'
                                                }`}
                                            >
                                                {d.label}
                                            </button>
                                        );
                                    })}
                                </div>
                                <p className="text-[11px] text-muted-foreground mt-1">Si no seleccionas ningún día, aplica todos los días.</p>
                            </Field>

                            <Field label="Zona horaria" icon={Globe} error={errors.timezone?.[0]}>
                                <Input
                                    value={editing.timezone}
                                    onChange={e => setEditing(s => ({ ...s, timezone: e.target.value }))}
                                    placeholder="America/Bogota"
                                />
                            </Field>

                            <Field label="Mensaje fuera de horario" icon={MessageSquare} error={errors.out_of_hours_message?.[0]}>
                                <textarea
                                    value={editing.out_of_hours_message}
                                    onChange={e => setEditing(s => ({ ...s, out_of_hours_message: e.target.value }))}
                                    placeholder="Hola {name}, nuestro horario es de {start} a {end}..."
                                    rows={4}
                                    maxLength={4096}
                                    className="w-full rounded-xl border border-border/70 bg-background/80 px-4 py-2.5 text-sm placeholder:text-muted-foreground/60 focus:outline-none focus:ring-2 focus:ring-teal-500/30 resize-y"
                                />
                                <p className="text-[11px] text-muted-foreground mt-1">
                                    Variables: <code className="text-foreground">{'{name}'}</code>, <code className="text-foreground">{'{phone}'}</code>, <code className="text-foreground">{'{start}'}</code>, <code className="text-foreground">{'{end}'}</code>
                                </p>
                            </Field>

                            <Field label="Cooldown (minutos)" icon={Clock} error={errors.cooldown_minutes?.[0]}>
                                <Input
                                    type="number"
                                    min={0}
                                    max={10080}
                                    value={editing.cooldown_minutes}
                                    onChange={e => setEditing(s => ({ ...s, cooldown_minutes: Number(e.target.value) }))}
                                />
                                <p className="text-[11px] text-muted-foreground mt-1">Tiempo mínimo entre dos envíos del mensaje a la misma conversación.</p>
                            </Field>

                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={!!editing.active}
                                    onChange={e => setEditing(s => ({ ...s, active: e.target.checked }))}
                                    className="size-4 rounded border-border/60 text-teal-600 focus:ring-teal-500/30"
                                />
                                <span className="text-foreground">Activo</span>
                            </label>
                        </div>

                        <div className="flex gap-2 pt-5">
                            <Button onClick={save} disabled={saving} className="flex-1 gap-2 bg-teal-600 hover:bg-teal-500 text-white">
                                {saving && <Loader2 className="size-4 animate-spin" />}
                                <Save className="size-4" />
                                Guardar
                            </Button>
                            <Button onClick={() => setEditing(null)} variant="outline" disabled={saving}>
                                Cancelar
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

/* ───────────────────────── Página principal ───────────────────────── */
export default function SettingsIndex({ sessions = [] }) {
    const [activeTab, setActiveTab] = useState('perfil');

    return (
        <AppLayout breadcrumb={['Configuración']}>
            <Head title="Configuración" />
            <div className="flex flex-col gap-8 p-6 lg:p-8">
                {/* Header */}
                <div>
                    <h1 className="text-2xl font-bold text-foreground tracking-tight">Configuración</h1>
                    <p className="text-sm text-muted-foreground mt-1.5">Gestiona tu perfil, seguridad y preferencias de la cuenta</p>
                </div>

                <div className="flex flex-col lg:flex-row gap-8 min-h-0">
                    {/* Sidebar nav */}
                    <nav className="w-full lg:w-56 flex-shrink-0">
                        <div className="flex flex-row lg:flex-col gap-1 overflow-x-auto lg:overflow-visible pb-2 lg:pb-0">
                            {TABS.map(tab => {
                                const isActive = activeTab === tab.id;
                                return (
                                    <button
                                        key={tab.id}
                                        onClick={() => setActiveTab(tab.id)}
                                        className={`flex items-center gap-3 w-full text-left rounded-xl px-3.5 py-2.5 text-sm transition-all duration-200 whitespace-nowrap ${
                                            isActive
                                                ? 'bg-teal-500/10 dark:bg-teal-500/15 text-teal-700 dark:text-teal-300 font-semibold shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground hover:bg-muted/40 dark:hover:bg-muted/20'
                                        }`}
                                    >
                                        <tab.Icon className={`size-4 flex-shrink-0 transition-colors ${
                                            isActive ? 'text-teal-600 dark:text-teal-400' : ''
                                        }`} />
                                        <span>{tab.label}</span>
                                        {isActive && (
                                            <span className="ml-auto size-1.5 rounded-full bg-teal-500 flex-shrink-0 hidden lg:block" />
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    </nav>

                    {/* Content */}
                    <div className={`flex-1 min-w-0 ${activeTab === 'whatsapp' ? 'max-w-5xl' : 'max-w-3xl'}`}>
                        {activeTab === 'perfil'     && <TabPerfil />}
                        {activeTab === 'contrasena' && <TabContrasena />}
                        {activeTab === 'dos-pasos'  && <TabDosPasos />}
                        {activeTab === 'sesiones'   && <TabSesiones sessions={sessions} />}
                        {activeTab === 'apariencia' && <TabApariencia />}
                        {activeTab === 'whatsapp'   && <TabWhatsApp />}
                        {activeTab === 'horarios'   && <TabHorarios />}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
