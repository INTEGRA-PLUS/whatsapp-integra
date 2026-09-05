import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Loader2, MessageCircle, Smartphone } from 'lucide-react';

/**
 * Botón del registro insertado de Meta (Embedded Signup).
 *
 * Abre la ventana oficial de Meta donde el cliente entra con su Facebook,
 * elige su empresa y su número, y autoriza. Al terminar, Meta devuelve dos
 * cosas por caminos distintos y hacen falta las dos:
 *
 *  - por el callback de FB.login, un `code` de un solo uso;
 *  - por eventos `postMessage` (lo que Meta llama "session logging"), el
 *    waba_id y el phone_number_id.
 *
 * Por eso el listener se registra ANTES de abrir la ventana y lo capturado se
 * guarda en un ref: cuando llega el callback, los identificadores ya tienen
 * que estar ahí. Guardarlos en estado no serviría — el callback se cierra
 * sobre el valor que hubiera al montar.
 *
 * Si falta configuración en el servidor el botón no se pinta: se sigue
 * conectando a mano, como siempre.
 *
 * Los dos botones son este mismo código con distinto `featureType`. Lo que
 * separa un camino del otro, y lo que la coexistencia le cambia al cliente en
 * su celular, está en docs/conexion-whatsapp.md.
 */
export default function EmbeddedSignupButton({ onConnected }) {
    const [config, setConfig] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const sessionInfo = useRef(null);

    useEffect(() => {
        let vivo = true;

        axios.get('/api/embedded-signup/config')
            .then(({ data }) => { if (vivo && data.enabled) setConfig(data); })
            .catch(() => { /* sin configuración no hay botón; no es un error que mostrar */ });

        return () => { vivo = false; };
    }, []);

    // Carga del SDK de Meta. Se hace una sola vez y sólo cuando hay
    // configuración: no tiene sentido traer el script en una instalación que
    // aún conecta a mano.
    useEffect(() => {
        if (!config || document.getElementById('facebook-jssdk')) return;

        window.fbAsyncInit = function () {
            window.FB.init({
                appId: config.app_id,
                autoLogAppEvents: true,
                xfbml: true,
                version: config.api_version,
            });
        };

        const script = document.createElement('script');
        script.id = 'facebook-jssdk';
        script.src = 'https://connect.facebook.net/en_US/sdk.js';
        script.async = true;
        script.defer = true;
        script.crossOrigin = 'anonymous';
        document.body.appendChild(script);
    }, [config]);

    // Session logging: Meta manda el waba_id y el phone_number_id por
    // postMessage, no por el callback. El listener vive todo el tiempo que
    // viva el botón para no perderse el evento por registrarlo tarde.
    useEffect(() => {
        function onMessage(event) {
            if (!event.origin.endsWith('facebook.com')) return;

            try {
                const data = JSON.parse(event.data);
                if (data.type !== 'WA_EMBEDDED_SIGNUP') return;

                if (data.event === 'FINISH' || data.event === 'FINISH_ONLY_WABA') {
                    sessionInfo.current = {
                        waba_id: data.data?.waba_id,
                        phone_number_id: data.data?.phone_number_id,
                    };
                } else if (data.event === 'CANCEL') {
                    sessionInfo.current = null;
                }
            } catch {
                // Meta manda por este canal mensajes que no son JSON; se ignoran.
            }
        }

        window.addEventListener('message', onMessage);

        return () => window.removeEventListener('message', onMessage);
    }, []);

    const launch = useCallback((coexistencia = false) => {
        setError(null);

        if (!window.FB) {
            setError('El conector de Meta todavía está cargando. Espera un momento y vuelve a intentarlo.');
            return;
        }

        sessionInfo.current = null;

        window.FB.login((response) => {
            const code = response?.authResponse?.code;

            // Sin código el cliente cerró la ventana o no autorizó. No es un
            // fallo que haya que explicar: simplemente no pasó nada.
            if (!code) return;

            const info = sessionInfo.current;

            if (!info?.waba_id || !info?.phone_number_id) {
                setError('Meta autorizó la conexión pero no devolvió la cuenta ni el número. Vuelve a intentarlo, y si se repite conéctalo a mano.');
                return;
            }

            setLoading(true);

            axios.post('/api/embedded-signup', { code, ...info })
                .then(({ data }) => onConnected?.(data))
                .catch(err => setError(err?.response?.data?.message ?? 'No se pudo completar la conexión.'))
                .finally(() => setLoading(false));
        }, {
            config_id: config.config_id,
            response_type: 'code',
            override_default_response_type: true,
            // `featureType` es lo único que separa los dos caminos. Sin él,
            // Meta rechaza cualquier número que ya tenga WhatsApp; con él,
            // ofrece conectar el que el negocio ya viene usando y conservar
            // ambos lados sincronizados.
            extras: coexistencia
                ? { setup: {}, featureType: 'whatsapp_business_app_onboarding' }
                : { setup: {} },
        });
    }, [config, onConnected]);

    if (!config) return null;

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2">
                <Button onClick={() => launch(false)} disabled={loading} className="gap-2 bg-[#1877f2] hover:bg-[#166fe0] text-white">
                    {loading ? <Loader2 className="size-4 animate-spin" /> : <MessageCircle className="size-4" />}
                    {loading ? 'Conectando…' : 'Conectar un número nuevo'}
                </Button>
                {/* El número que el negocio ya usa a diario no pasa por el
                    camino de arriba: Meta lo rechaza por tener WhatsApp. Este
                    es el único que lo admite, y deja la app del celular
                    funcionando. */}
                <Button onClick={() => launch(true)} disabled={loading} variant="outline" className="gap-2">
                    <Smartphone className="size-4" />
                    Conectar mi WhatsApp Business actual
                </Button>
            </div>
            {/* Se avisa aquí y no sólo en la pantalla de Meta: el consentimiento
                de Meta cubre compartir el historial, pero NO menciona ninguno de
                estos cambios en la app del celular. Quien pulsa tiene que
                saberlos antes, no descubrirlos después. */}
            <details className="max-w-xl rounded-lg border border-border/60 bg-muted/20 px-3 py-2">
                <summary className="cursor-pointer text-xs font-medium text-foreground">
                    Qué le pasa a tu WhatsApp Business si conectas el número que ya usas
                </summary>
                <div className="mt-2 space-y-2 text-[11px] text-muted-foreground">
                    <p>Sigues respondiendo desde el celular y no pierdes ningún chat. Pero en esa app:</p>
                    <ul className="list-disc pl-4 space-y-1">
                        <li>Se desactivan los <strong>mensajes temporales</strong>, los de <strong>ver una vez</strong> y la <strong>ubicación en tiempo real</strong> en los chats 1 a 1.</li>
                        <li>Las <strong>listas de difusión</strong> quedan de solo lectura: las existentes se leen, no se crean nuevas.</li>
                        <li>Los <strong>dispositivos vinculados se desconectan</strong>, WhatsApp Web incluido. Se vuelven a vincular después, pero se caen en el momento.</li>
                        <li>Los <strong>grupos no se sincronizan</strong>. Siguen en tu app, no aparecen aquí.</li>
                    </ul>
                    <p>
                        Además, el número queda con un tope de <strong>20 mensajes por segundo</strong>, y Meta
                        te ofrecerá compartir hasta <strong>6 meses</strong> de historial: si aceptas, esas
                        conversaciones se copian a este sistema.
                    </p>
                </div>
            </details>
            {error && <p className="text-xs text-destructive max-w-md">{error}</p>}
        </div>
    );
}
