import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { useAppearance } from '@/hooks/use-appearance';
import { Button } from '@/components/ui/button';
import {
    User, Lock, ShieldCheck, Monitor, Moon, Sun, SunMoon,
    Smartphone, Globe, LogOut, CheckCircle2, Clock, Palette,
    KeyRound, Eye, EyeOff, Save, Mail, UserCircle,
} from 'lucide-react';

const TABS = [
    { id: 'perfil',      label: 'Perfil',                   Icon: User },
    { id: 'contrasena',  label: 'Contraseña',               Icon: Lock },
    { id: 'dos-pasos',   label: 'Autenticación 2FA',        Icon: ShieldCheck },
    { id: 'sesiones',    label: 'Sesiones del navegador',    Icon: Monitor },
    { id: 'apariencia',  label: 'Apariencia',               Icon: Palette },
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
                    <div className="flex-1 min-w-0 max-w-3xl">
                        {activeTab === 'perfil'     && <TabPerfil />}
                        {activeTab === 'contrasena' && <TabContrasena />}
                        {activeTab === 'dos-pasos'  && <TabDosPasos />}
                        {activeTab === 'sesiones'   && <TabSesiones sessions={sessions} />}
                        {activeTab === 'apariencia' && <TabApariencia />}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
