import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Loader2, MessageCircle, Smartphone } from 'lucide-react';
import AsistenteConexion from '@/components/AsistenteConexion';

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
    // El aviso previo sólo aplica al camino de coexistencia: conectar un número
    // nuevo no le cambia nada al celular de nadie.
    const [mostrarAviso, setMostrarAviso] = useState(false);
    const sessionInfo = useRef(null);
    // Qué eventos mandó Meta de verdad. Sin esto, cuando el registro fallaba no
    // quedaba nada en ninguna parte: el fallo moría en el navegador del cliente
    // y había que adivinarlo.
    const rastro = useRef([]);
    const cancelado = useRef(false);

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

                rastro.current.push(data.event ?? '(sin evento)');

                // La coexistencia termina con su propio evento, y su payload
                // trae SÓLO el waba_id: el número ya existe, así que Meta no
                // lo devuelve. Exigir aquí el phone_number_id haría fallar el
                // camino justo al final, después de que el cliente ya aceptó
                // todo. Lo resuelve el servidor a partir del WABA.
                if (['FINISH', 'FINISH_ONLY_WABA', 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'].includes(data.event)) {
                    sessionInfo.current = {
                        waba_id: data.data?.waba_id,
                        phone_number_id: data.data?.phone_number_id ?? null,
                    };
                } else if (data.event === 'CANCEL') {
                    sessionInfo.current = null;
                    cancelado.current = true;
                }
            } catch {
                // Meta manda por este canal mensajes que no son JSON; se ignoran.
            }
        }

        window.addEventListener('message', onMessage);

        return () => window.removeEventListener('message', onMessage);
    }, []);

    /**
     * Espera a que llegue el `postMessage` con la cuenta.
     *
     * El callback de `FB.login` y el mensaje de Meta son dos canales
     * independientes y no hay orden garantizado: si el callback gana la carrera
     * —pasa— el ref todavía está vacío, y antes eso bastaba para abortar un
     * registro que había ido bien.
     *
     * Cuatro segundos: lo que tarda en llegar cuando llega, con margen. Si se
     * agotan no se aborta nada — el servidor sabe sacar el WABA del token.
     */
    const esperarLaCuenta = useCallback(async (ms = 4000) => {
        const hasta = Date.now() + ms;

        while (Date.now() < hasta) {
            if (sessionInfo.current?.waba_id) return sessionInfo.current;
            await new Promise(r => setTimeout(r, 150));
        }

        return sessionInfo.current;
    }, []);

    /**
     * Lo que pasa después de que Meta devuelve el código.
     *
     * Va aparte porque el callback de `FB.login` no puede ser asíncrono —el SDK
     * comprueba el tipo y lo rechaza— y aquí sí hace falta esperar al mensaje
     * con la cuenta.
     */
    const terminar = useCallback(async (code, coexistencia) => {
        const info = await esperarLaCuenta();

        if (cancelado.current) {
            setError('Cerraste la ventana de Meta antes de terminar. Vuelve a abrirla y llega hasta el último paso.');
            return;
        }

        setLoading(true);

        // Se manda aunque falte la cuenta. El `postMessage` de Meta es una
        // comodidad, no la fuente: el token que el servidor canjea con este
        // código sabe a qué WABA pertenece. Antes se abortaba aquí y el código
        // —de un solo uso— se tiraba, así que el cliente tenía que repetir la
        // ventana entera por un mensaje que no llegó a tiempo.
        axios.post('/api/embedded-signup', {
            code,
            waba_id: info?.waba_id ?? null,
            phone_number_id: info?.phone_number_id ?? null,
            diagnostico: { eventos: rastro.current, coexistencia },
        })
            .then(({ data }) => onConnected?.(data))
            .catch(err => setError(err?.response?.data?.message ?? 'No se pudo completar la conexión.'))
            .finally(() => setLoading(false));
    }, [esperarLaCuenta, onConnected]);

    const launch = useCallback((coexistencia = false) => {
        setError(null);

        if (!window.FB) {
            setError('El conector de Meta todavía está cargando. Espera un momento y vuelve a intentarlo.');
            return;
        }

        sessionInfo.current = null;
        rastro.current = [];
        cancelado.current = false;

        // El callback NO puede ser `async`.
        //
        // El SDK de Meta comprueba el tipo de lo que se le pasa y una función
        // asíncrona no lo pasa: revienta con «Expression is of type
        // asyncfunction, not function» y la ventana no llega a abrirse. Lo
        // aprendimos rompiéndolo el 22-sep-2026, al añadir la espera de la
        // cuenta: el arreglo de un fallo silencioso se llevó por delante el
        // camino entero.
        //
        // La espera vive dentro, en una función aparte que sí puede serlo.
        window.FB.login((response) => {
            const code = response?.authResponse?.code;

            // Sin código el cliente cerró la ventana o no autorizó. No es un
            // fallo que haya que explicar: simplemente no pasó nada.
            if (!code) return;

            terminar(code, coexistencia);
        }, {
            config_id: config.config_id,
            response_type: 'code',
            override_default_response_type: true,
            // `featureType` es lo único que separa los dos caminos. Sin él,
            // Meta rechaza cualquier número que ya tenga WhatsApp; con él,
            // ofrece conectar el que el negocio ya viene usando y conservar
            // ambos lados sincronizados.
            // `sessionInfoVersion: 3` no es opcional: sin él Meta ignora el
            // featureType y sirve el flujo normal, que rechaza cualquier
            // número que ya tenga WhatsApp. El síntoma es exactamente el
            // error que se quería evitar, así que parece un problema del
            // número y no de la petición.
            extras: coexistencia
                ? { setup: {}, featureType: 'whatsapp_business_app_onboarding', sessionInfoVersion: '3' }
                : { setup: {} },
        });
    }, [config, terminar]);

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
                    funcionando. Antes de abrir la ventana se explica qué le
                    acompaña al cliente en un asistente de cuatro pasos: qué le
                    cambia, los requisitos, el paso previo en Meta Business
                    Suite y qué va a ver. Ahí se caían casi todos los
                    intentos. */}
                <Button onClick={() => setMostrarAviso(true)} disabled={loading} variant="outline" className="gap-2">
                    <Smartphone className="size-4" />
                    Conectar mi WhatsApp Business actual
                </Button>
            </div>

            <AsistenteConexion
                open={mostrarAviso}
                onCancel={() => setMostrarAviso(false)}
                onLaunch={() => { setMostrarAviso(false); launch(true); }}
            />

            {error && <p className="text-xs text-destructive max-w-md">{error}</p>}
        </div>
    );
}
