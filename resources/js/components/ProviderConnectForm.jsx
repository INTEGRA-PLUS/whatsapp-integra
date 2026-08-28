import { useState } from 'react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Link2, Mail, KeyRound, ShieldCheck, Loader2 } from 'lucide-react';

/**
 * El formulario que conecta un proveedor.
 *
 * Vive fuera de la pantalla de integraciones porque hace falta en dos sitios: el
 * asistente de Integraciones y el módulo de menús, donde la revisión avisa de
 * que el token no puede leer contratos. Mandar al admin a otra pantalla para
 * pegar dos campos era perderlo por el camino justo cuando ya sabía qué hacer.
 *
 * Conectar aquí conecta TODAS las funciones del proveedor (ver
 * IntegrationProvider en el backend): es un solo entorno y un solo token.
 */
export default function ProviderConnectForm({
    integrationKey = 'invoice_payments',
    initialBaseUrl = '',
    onConnected,
    onError,
    autoFocus = true,
}) {
    const [mode, setMode] = useState('login');
    const [baseUrl, setBaseUrl] = useState(initialBaseUrl);
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [token, setToken] = useState('');
    const [connecting, setConnecting] = useState(false);
    const [errors, setErrors] = useState({});

    async function connect() {
        const url = baseUrl.trim().replace(/\/+$/, '');
        const e = {};

        // Integra es multi-tenant: cada empresa entra a la URL de su propio entorno.
        if (!/^https?:\/\/.+\..+/i.test(url)) {
            e.base_url = 'Ingresa la URL completa de tu entorno Integra (ej. https://miempresa.integra.com).';
        }
        if (mode === 'login') {
            if (!email.trim()) e.email = 'Ingresa el email de tu usuario de Integra.';
            if (!password) e.password = 'Ingresa tu contraseña de Integra.';
        } else if (!token.trim()) {
            e.token = 'Pega el token itg_ de la API de tu entorno Integra.';
        }

        setErrors(e);
        if (Object.keys(e).length) return;

        setConnecting(true);
        try {
            // El backend valida contra la API v1 de Integra antes de guardar: con
            // credenciales canjea el login por un token itg_ recién emitido (la
            // contraseña sólo viaja en esta petición, nunca se guarda); con token
            // pegado, valida la pareja URL+token. El token queda cifrado.
            const payload = mode === 'login'
                ? { base_url: url, email: email.trim(), password }
                : { base_url: url, token: token.trim() };

            const { data } = await axios.post(`/api/integrations/${integrationKey}/connect`, payload);
            onConnected?.(data);
        } catch (err) {
            onError?.(err?.response?.data?.message ?? 'No se pudo conectar con Integra.');
        } finally {
            setConnecting(false);
        }
    }

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                {mode === 'login'
                    ? 'Indica la dirección de tu entorno Integra e inicia sesión con tu usuario. Generamos el token de la API con todos los permisos que necesita la plataforma y validamos la conexión antes de guardar.'
                    : <>Indica la dirección de tu entorno Integra y un token de su API pública (se genera en el servidor de Integra con <code className="font-mono text-xs">php artisan api:token</code>). Validamos la conexión antes de guardar.</>}
            </p>

            <Field label="URL de tu entorno Integra" icon={Link2} error={errors.base_url}>
                <input
                    type="url"
                    value={baseUrl}
                    onChange={e => { setBaseUrl(e.target.value); if (errors.base_url) setErrors(p => ({ ...p, base_url: null })); }}
                    onKeyDown={e => { if (e.key === 'Enter') connect(); }}
                    placeholder="https://miempresa.integra.com"
                    className={`${inputClass} font-mono`}
                    autoFocus={autoFocus}
                />
            </Field>

            {mode === 'login' ? (
                <>
                    <Field label="Email de tu usuario de Integra" icon={Mail} error={errors.email}>
                        <input
                            type="email"
                            value={email}
                            onChange={e => { setEmail(e.target.value); if (errors.email) setErrors(p => ({ ...p, email: null })); }}
                            onKeyDown={e => { if (e.key === 'Enter') connect(); }}
                            placeholder="usuario@miempresa.com"
                            autoComplete="off"
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Contraseña" icon={KeyRound} error={errors.password}>
                        <input
                            type="password"
                            value={password}
                            onChange={e => { setPassword(e.target.value); if (errors.password) setErrors(p => ({ ...p, password: null })); }}
                            onKeyDown={e => { if (e.key === 'Enter') connect(); }}
                            placeholder="Tu contraseña de Integra"
                            autoComplete="new-password"
                            className={inputClass}
                        />
                    </Field>
                </>
            ) : (
                <Field label="Token de la API" icon={ShieldCheck} error={errors.token}>
                    <input
                        type="password"
                        value={token}
                        onChange={e => { setToken(e.target.value); if (errors.token) setErrors(p => ({ ...p, token: null })); }}
                        onKeyDown={e => { if (e.key === 'Enter') connect(); }}
                        placeholder="itg_xxxxxxxxxxxxxxxxxxxxxxxx"
                        autoComplete="off"
                        className={`${inputClass} font-mono`}
                    />
                    <p className="text-[11px] text-muted-foreground mt-1">
                        Emítelo sin <code className="font-mono">--abilities</code> sólo si tu Integra los concede todos;
                        si no, pide <code className="font-mono">contactos.leer, facturas.leer, pagos.leer, pagos.registrar,
                        radicados.leer, radicados.crear, contratos.leer</code>. Sin los dos últimos, el menú no puede
                        responder el estado del servicio ni abrir reportes.
                    </p>
                </Field>
            )}

            <div className="flex items-start gap-3 rounded-xl border bg-muted/40 p-4 text-xs text-muted-foreground">
                <ShieldCheck className="mt-0.5 size-4 shrink-0 text-teal-600 dark:text-teal-400" />
                <p>
                    {mode === 'login'
                        ? 'Tu contraseña sólo se usa una vez para generar el token y no se guarda. El token queda cifrado y sólo se usa desde el servidor.'
                        : 'El token se guarda cifrado y sólo se usa desde el servidor. Nunca se muestra de nuevo ni viaja al navegador.'}
                </p>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <Button onClick={connect} disabled={connecting} className="gap-2">
                    {connecting ? <Loader2 className="size-4 animate-spin" /> : <Link2 className="size-4" />} Conectar con Integra
                </Button>
                <button
                    type="button"
                    onClick={() => { setMode(m => (m === 'login' ? 'token' : 'login')); setErrors({}); }}
                    className="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                >
                    {mode === 'login' ? '¿Prefieres pegar un token de API?' : 'Volver a iniciar sesión con mi usuario'}
                </button>
            </div>
        </div>
    );
}

export const inputClass = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50';

export function Field({ label, icon: Icon, error, children }) {
    return (
        <div className="space-y-1.5">
            <label className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                {Icon && <Icon className="size-3.5 text-muted-foreground" />} {label}
            </label>
            {children}
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}
