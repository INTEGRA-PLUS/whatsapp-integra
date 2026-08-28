import { ArrowRight, ArrowLeft, Blocks, Webhook, ShieldCheck, AlertTriangle, CheckCircle2 } from 'lucide-react';

/**
 * Qué es cada cosa de esta pantalla, para quien la abre por primera vez.
 *
 * La duda que la motivó es real y la tuvo alguien que conoce el producto: el
 * complemento pedía una URL, el webhook pedía una URL, y las dos eran del mismo
 * servidor. Sin saber que van en direcciones contrarias no hay forma de
 * adivinar cuál es cuál — ni de entender por qué en una hay que poner el
 * dominio y en la otra una ruta concreta.
 *
 * Se explica con el ejemplo que causó el lío, no en abstracto.
 */
export default function IntegrationsHelp({ onClose }) {
    return (
        <div className="space-y-8 text-sm">
            <Intro />
            <Direction />
            <Complements />
            <Webhooks />
            <Choosing />
            <Health />

            <div className="flex justify-end border-t pt-4">
                <button type="button" onClick={onClose}
                    className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                    Entendido
                </button>
            </div>
        </div>
    );
}

function Section({ title, icon: Icon, children }) {
    return (
        <section className="space-y-3">
            <h3 className="flex items-center gap-2 text-base font-semibold text-foreground">
                {Icon && <Icon className="size-4 text-muted-foreground" />} {title}
            </h3>
            {children}
        </section>
    );
}

function Intro() {
    return (
        <p className="text-muted-foreground">
            Esta pantalla tiene dos cosas que se parecen y hacen lo contrario. Las dos piden una
            dirección de internet, y muchas veces del mismo servidor — de ahí la confusión. Lo que
            las separa es <strong className="text-foreground">quién llama a quién</strong>.
        </p>
    );
}

function Direction() {
    return (
        <Section title="La diferencia, en una imagen">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="rounded-xl border bg-card p-4">
                    <div className="flex items-center gap-2 text-xs font-medium text-foreground">
                        <Blocks className="size-4 text-muted-foreground" /> Complemento
                    </div>
                    <div className="mt-3 flex items-center gap-2 text-xs">
                        <span className="rounded-md bg-primary/10 px-2 py-1 font-medium text-primary">Nosotros</span>
                        <ArrowRight className="size-4 text-muted-foreground" />
                        <span className="rounded-md bg-muted px-2 py-1">Tu software</span>
                    </div>
                    <p className="mt-3 text-xs text-muted-foreground">
                        Vamos a <strong className="text-foreground">preguntarle</strong> algo y esperamos la
                        respuesta: «¿cuánto debe este cliente?», «¿está activo su servicio?».
                        Lo que conteste es lo que el cliente recibe por WhatsApp.
                    </p>
                </div>

                <div className="rounded-xl border bg-card p-4">
                    <div className="flex items-center gap-2 text-xs font-medium text-foreground">
                        <Webhook className="size-4 text-muted-foreground" /> Webhook
                    </div>
                    <div className="mt-3 flex items-center gap-2 text-xs">
                        <span className="rounded-md bg-muted px-2 py-1">Tu software</span>
                        <ArrowLeft className="size-4 text-muted-foreground" />
                        <span className="rounded-md bg-primary/10 px-2 py-1 font-medium text-primary">Nosotros</span>
                    </div>
                    <p className="mt-3 text-xs text-muted-foreground">
                        Vamos a <strong className="text-foreground">avisarle</strong> de que acaba de pasar algo:
                        «un agente respondió», «se creó un ticket». No esperamos datos de vuelta,
                        sólo que confirme que lo recibió.
                    </p>
                </div>
            </div>

            <p className="text-muted-foreground">
                Por eso uno necesita un <strong className="text-foreground">token</strong> y el otro no: para
                entrar en casa ajena hay que tener llave, para dejar una nota en el buzón no.
            </p>
        </Section>
    );
}

function Complements() {
    return (
        <Section title="Complementos" icon={Blocks}>
            <p className="text-muted-foreground">
                Un complemento es el software de gestión que ya usa tu empresa, conectado para que sus
                datos respondan solos por WhatsApp. Hoy hay uno, Integra, y la lista crecerá.
            </p>

            <Row label="Qué le pones">
                El <strong className="text-foreground">dominio</strong> de tu entorno, no una ruta:{' '}
                <code className="font-mono text-xs">https://miempresa.integra.com</code>. Las direcciones
                concretas de cada consulta las construimos nosotros a partir de ahí.
            </Row>
            <Row label="Cómo se autentica">
                Con tu usuario y contraseña de Integra, una sola vez. Con eso generamos un token de su
                API con los permisos que hacen falta. La contraseña no se guarda; el token queda
                cifrado y nunca vuelve al navegador.
            </Row>
            <Row label="Qué habilita">
                Una conexión sirve para todo: consultar y registrar pagos desde el chat, mantener la
                agenda de contactos al día, y que el menú de WhatsApp responda facturas, estado del
                servicio, consumo y reportes. No hay que conectarlo una vez por función.
            </Row>
            <Row label="Cómo saber si funciona">
                El botón <em>Verificar conexión</em> pregunta de verdad a tu software. Y en{' '}
                <em>Menús de WhatsApp</em>, la revisión de arriba dice qué permisos tiene tu token y qué
                opciones se quedarían sin responder por faltarle alguno.
            </Row>
        </Section>
    );
}

function Webhooks() {
    return (
        <Section title="Webhooks" icon={Webhook}>
            <p className="text-muted-foreground">
                Un webhook es un aviso que te mandamos cuando pasa algo en tus conversaciones, para que
                tu sistema se entere sin tener que preguntarnos cada rato. Sirve para llevar los chats a
                tu CRM, abrir tickets, disparar automatizaciones en n8n o Zapier.
            </p>

            <Row label="Qué le pones">
                La <strong className="text-foreground">ruta exacta</strong> de tu servidor que está
                preparada para recibir los avisos —algo como{' '}
                <code className="font-mono text-xs">https://miempresa.com/api/webhooks/whatsapp</code>—,
                no la página principal.
            </Row>
            <Row label="Cómo llega">
                Una petición <code className="font-mono text-xs">POST</code> con el evento en JSON y la
                cabecera <code className="font-mono text-xs">X-Webhook-Signature</code>: un HMAC-SHA256
                del cuerpo hecho con el secreto del webhook. Tu sistema calcula lo mismo y compara; si
                no cuadra, la petición no viene de nosotros y hay que descartarla.
            </Row>
            <Row label="Qué esperamos de vuelta">
                Cualquier respuesta 2xx. Si falla, lo reintentamos solos. No seguimos redirecciones: pon
                la dirección definitiva.
            </Row>

            <div className="flex gap-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
                <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" />
                <div className="text-xs text-amber-800 dark:text-amber-300">
                    <p className="font-medium">El error más común: poner la web en vez de la ruta.</p>
                    <p className="mt-1">
                        Si pones <code className="font-mono">https://miempresa.com/software</code>, que es
                        una página para mirar, tu servidor responderá{' '}
                        <strong>405 Method Not Allowed</strong>: la dirección existe, pero no acepta que le
                        manden datos. El webhook aparecerá activo y no entregará nada. Pídele a quien
                        lleva tu software la ruta que escucha webhooks; si no existe, hay que crearla
                        antes.
                    </p>
                </div>
            </div>
        </Section>
    );
}

function Choosing() {
    return (
        <Section title="Cuál necesito">
            <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                    <thead className="text-muted-foreground">
                        <tr className="border-b">
                            <th className="py-2 pr-4 font-medium">Lo que quieres</th>
                            <th className="py-2 font-medium">Lo que usas</th>
                        </tr>
                    </thead>
                    <tbody className="text-foreground">
                        {[
                            ['Que el cliente consulte su factura por WhatsApp', 'Complemento'],
                            ['Que el bot diga por qué está suspendido el servicio', 'Complemento'],
                            ['Cobrar desde el chat sin salir a otro sistema', 'Complemento'],
                            ['Que tu CRM guarde cada mensaje que responde un agente', 'Webhook'],
                            ['Abrir un ticket en tu sistema cuando el bot crea un radicado', 'Webhook'],
                            ['Disparar una automatización en n8n al cerrar un chat', 'Webhook'],
                        ].map(([want, use]) => (
                            <tr key={want} className="border-b last:border-0">
                                <td className="py-2 pr-4">{want}</td>
                                <td className="py-2 font-medium">{use}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="text-xs text-muted-foreground">
                Regla rápida: si la respuesta la necesita <strong className="text-foreground">el
                cliente</strong> en su chat, es un complemento. Si la necesita{' '}
                <strong className="text-foreground">tu sistema</strong>, es un webhook. Se pueden usar los
                dos a la vez, y es lo normal.
            </p>
        </Section>
    );
}

function Health() {
    return (
        <Section title="Cómo saber si algo está fallando" icon={ShieldCheck}>
            <p className="text-muted-foreground">
                Ninguna de las dos avisa sola cuando se rompe, así que lo dice la pantalla:
            </p>
            <ul className="space-y-2 text-xs text-muted-foreground">
                <li className="flex gap-2">
                    <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-emerald-600" />
                    <span>
                        Cada webhook muestra cuántas entregas lleva, cuántas salieron bien y qué dijo la
                        última. <strong className="text-foreground">«Activo» sólo significa que está
                        encendido</strong>, no que esté llegando.
                    </span>
                </li>
                <li className="flex gap-2">
                    <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-emerald-600" />
                    <span>
                        El complemento muestra si el token sigue vivo y qué permisos tiene. Un token puede
                        estar conectado y no poder leer contratos: entonces esas opciones del menú no
                        responden.
                    </span>
                </li>
                <li className="flex gap-2">
                    <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-emerald-600" />
                    <span>
                        El historial de cada webhook guarda lo que respondió tu servidor, que suele ser
                        donde está la pista.
                    </span>
                </li>
            </ul>
        </Section>
    );
}

function Row({ label, children }) {
    return (
        <div className="grid gap-1 sm:grid-cols-[140px_1fr] sm:gap-4">
            <p className="text-xs font-medium text-foreground">{label}</p>
            <p className="text-xs text-muted-foreground">{children}</p>
        </div>
    );
}
