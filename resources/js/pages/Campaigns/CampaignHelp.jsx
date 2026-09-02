import { useState } from 'react';
import {
    AlertTriangle, CheckCheck, Clock, FileText, Gauge, Users, X,
} from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * La documentación de campañas, dentro de la propia pantalla.
 *
 * Las reglas que gobiernan un envío masivo por WhatsApp no son nuestras y no se
 * adivinan: la ventana de 24 horas, por qué hace falta una plantilla aprobada,
 * qué diferencia hay entre marketing y utilidad, o por qué un mensaje figura
 * como enviado pero no como entregado. Quien lanza la campaña se entera aquí y
 * no por un cliente enfadado.
 */
const TABS = [
    { id: 'flujo', label: 'Cómo funciona' },
    { id: 'crear', label: 'Crear una campaña' },
    { id: 'estados', label: 'Los estados' },
    { id: 'fallos', label: 'Si algo falla' },
    { id: 'detalles', label: 'Límites y coste' },
];

export default function CampaignHelp({ campaigns = [], instances = [], onClose }) {
    const [tab, setTab] = useState('flujo');

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
            <div
                className="w-full max-w-3xl rounded-2xl bg-card shadow-xl border max-h-[90vh] flex flex-col"
                onClick={e => e.stopPropagation()}
            >
                <div className="flex items-start justify-between gap-4 px-6 pt-5">
                    <div>
                        <h2 className="text-lg font-semibold text-foreground">Cómo funcionan las campañas</h2>
                        <p className="text-sm text-muted-foreground">
                            Lo que conviene saber antes de escribirle a mucha gente a la vez.
                        </p>
                    </div>
                    <button onClick={onClose} className="text-muted-foreground hover:text-foreground">
                        <X className="size-5" />
                    </button>
                </div>

                <nav className="flex gap-1 border-b px-6 pt-4 overflow-x-auto">
                    {TABS.map(t => (
                        <button
                            key={t.id}
                            onClick={() => setTab(t.id)}
                            className={`whitespace-nowrap px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
                                tab === t.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {t.label}
                        </button>
                    ))}
                </nav>

                <div className="px-6 py-5 overflow-y-auto space-y-6 text-sm">
                    {tab === 'flujo' && <TabFlujo />}
                    {tab === 'crear' && <TabCrear instances={instances} campaigns={campaigns} />}
                    {tab === 'estados' && <TabEstados />}
                    {tab === 'fallos' && <TabFallos onIr={setTab} />}
                    {tab === 'detalles' && <TabDetalles />}
                </div>

                <div className="border-t px-6 py-3 flex justify-end">
                    <Button variant="outline" onClick={onClose}>Cerrar</Button>
                </div>
            </div>
        </div>
    );
}

const PASOS = [
    {
        icon: Clock,
        titulo: 'La regla que manda: la ventana de 24 horas',
        texto: 'WhatsApp solo deja escribir libremente durante las 24 horas siguientes al último mensaje del cliente. '
            + 'Una campaña va, por definición, a gente que no acaba de escribirte, así que el texto libre no llega.',
    },
    {
        icon: FileText,
        titulo: 'Por eso se envía una plantilla aprobada',
        texto: 'Una plantilla es un texto que Meta revisó y aprobó de antemano. Es lo único que WhatsApp entrega fuera de '
            + 'esa ventana. Se crean en la sección Plantillas y tardan minutos en aprobarse.',
    },
    {
        icon: Users,
        titulo: 'Eliges a quién y con qué datos',
        texto: 'Los destinatarios pueden salir de tus conversaciones, de los contactos del CRM, de una lista pegada o de un '
            + 'segmento guardado. Cada dato variable de la plantilla puede ser fijo o salir del propio destinatario.',
    },
    {
        icon: CheckCheck,
        titulo: 'Y ves el resultado real, uno por uno',
        texto: 'Enviado no es entregado, y entregado no es leído. El detalle de la campaña muestra los tres, y el motivo '
            + 'concreto de cada fallo. Todo lo enviado aparece además en el chat de cada cliente.',
    },
];

function TabFlujo() {
    return (
        <div className="space-y-5">
            <Lead>
                Una campaña es un aviso repetido, no un mensaje de chat: sale por un camino distinto y con reglas distintas.
                Estas cuatro cosas explican casi todo lo que verás.
            </Lead>

            <ol className="space-y-4">
                {PASOS.map(({ icon: Icon, titulo, texto }, i) => (
                    <li key={i} className="flex gap-3">
                        <span className="size-8 shrink-0 rounded-full bg-muted flex items-center justify-center">
                            <Icon className="size-4 text-muted-foreground" />
                        </span>
                        <div>
                            <div className="font-medium text-foreground">{titulo}</div>
                            <p className="text-muted-foreground">{texto}</p>
                        </div>
                    </li>
                ))}
            </ol>

            <Note tono="teal">
                Lo que se envía en una campaña queda también en la conversación del cliente. Si te contesta, el agente ve
                el hilo completo y sabe de qué le habla.
            </Note>
        </div>
    );
}

function TabCrear({ instances, campaigns }) {
    const listas = instances.filter(i => i.ready);
    const sinPlantilla = campaigns.filter(c => !c.uses_template).length;

    return (
        <div className="space-y-5">
            <Lead>El asistente son cuatro pasos y no deja avanzar con algo a medias.</Lead>

            <Section title="1 · Línea y nombre">
                <p className="text-muted-foreground">
                    La línea viene elegida; solo tienes que cambiarla si tienes varias. El nombre es interno: te sirve para
                    encontrar la campaña cuando alguien pregunte «¿qué se mandó el martes?».
                </p>
            </Section>

            <Section title="2 · Plantilla">
                <p className="text-muted-foreground">
                    Solo aparecen las plantillas <Field>APROBADAS</Field> de esa línea. Al elegir una verás la burbuja de
                    WhatsApp a la derecha. Si la plantilla lleva imagen, video o PDF arriba, se sube una vez y sirve para
                    todos los envíos. Cada <Field>{'{{1}}'}</Field> se rellena con un texto fijo o con un dato del
                    destinatario (su nombre, su teléfono, su identificación, o una columna del CSV que pegues).
                </p>
            </Section>

            <Section title="3 · Destinatarios">
                <p className="text-muted-foreground">
                    Cuatro fuentes que puedes mezclar: conversaciones, contactos del CRM, una lista pegada y los segmentos
                    que hayas guardado. Lo repetido se envía una sola vez, y los números que no son válidos se descartan
                    antes de crear nada, diciéndote cuáles.
                </p>
            </Section>

            <Section title="4 · Revisar y enviar">
                <p className="text-muted-foreground">
                    La vista previa muestra el mensaje con los datos del primer destinatario real: si algo está mal, se ve
                    ahí y no en el teléfono de mil clientes. Puedes enviar ahora, guardar el borrador o programarla por días.
                </p>
            </Section>

            <Section title="Cómo está tu cuenta ahora">
                <ul className="space-y-1.5 text-muted-foreground">
                    <li className="flex items-center gap-2">
                        <Punto ok={listas.length > 0} />
                        {listas.length > 0
                            ? `${listas.length} línea${listas.length > 1 ? 's' : ''} lista${listas.length > 1 ? 's' : ''} para enviar.`
                            : 'Ninguna línea tiene WhatsApp Business conectado todavía.'}
                    </li>
                    <li className="flex items-center gap-2">
                        <Punto ok={sinPlantilla === 0} />
                        {sinPlantilla === 0
                            ? 'Todas tus campañas usan plantilla.'
                            : `${sinPlantilla} campaña${sinPlantilla > 1 ? 's' : ''} antigua${sinPlantilla > 1 ? 's' : ''} de texto libre: no se pueden enviar, hay que rehacerlas con una plantilla.`}
                    </li>
                </ul>
            </Section>
        </div>
    );
}

const ESTADOS = [
    ['Pendiente', 'Todavía no le ha tocado el turno. Los envíos se escalonan a propósito.'],
    ['Enviado', 'WhatsApp aceptó el mensaje. Todavía no significa que el cliente lo tenga.'],
    ['Entregado', 'Llegó al teléfono del cliente (el doble check gris).'],
    ['Leído', 'El cliente abrió la conversación (el doble check azul).'],
    ['Fallido', 'WhatsApp lo rechazó. El motivo aparece en la misma fila, explicado.'],
    ['Omitido', 'No se le envió a propósito: pidió no recibir campañas, o se canceló el envío antes de llegarle.'],
];

function TabEstados() {
    return (
        <div className="space-y-5">
            <Lead>
                Enviado, entregado y leído son tres cosas distintas, y confundirlas es el malentendido más caro de un envío
                masivo. Meta confirma las dos últimas por su cuenta, minutos u horas después.
            </Lead>

            <div className="rounded-xl border divide-y">
                {ESTADOS.map(([estado, texto]) => (
                    <div key={estado} className="px-4 py-2.5">
                        <div className="font-medium text-foreground">{estado}</div>
                        <p className="text-muted-foreground">{texto}</p>
                    </div>
                ))}
            </div>

            <Note tono="amber">
                Un mensaje puede quedarse en «enviado» para siempre si el número no tiene WhatsApp o el teléfono no se
                conecta. No es un error de la campaña: es el destinatario.
            </Note>
        </div>
    );
}

const FALLOS = [
    {
        sintoma: 'Pasaron más de 24 horas desde el último mensaje del cliente',
        causa: 'La campaña intentó salir como texto libre. Solo puede pasar en campañas viejas, anteriores a las plantillas.',
        arreglo: 'Crea la campaña de nuevo eligiendo una plantilla aprobada.',
    },
    {
        sintoma: 'Un dato de la plantilla no tiene el formato esperado',
        causa: 'El encabezado o los datos variables no cuadran con la plantilla aprobada: falta la imagen, es de otro tipo, '
            + 'o el archivo ya no existe en WhatsApp (Meta los borra a los 30 días).',
        arreglo: 'Vuelve a subir el archivo del encabezado y revisa que cada variable tenga valor.',
    },
    {
        sintoma: 'El número no puede recibir mensajes de WhatsApp',
        causa: 'El número no tiene cuenta de WhatsApp, está mal escrito o fue dado de baja.',
        arreglo: 'Verifica el número con el cliente por otro medio. Reintentar dará el mismo resultado.',
    },
    {
        sintoma: 'WhatsApp no entrega mensajes de este tipo a esta persona',
        causa: 'El cliente tiene limitados los mensajes promocionales, o Meta decidió no entregar esa categoría a ese usuario.',
        arreglo: 'Si es un aviso operativo, usa una plantilla de utilidad en vez de una de marketing.',
    },
    {
        sintoma: 'La campaña va muy lenta o WhatsApp empieza a rechazar envíos',
        causa: 'Se está enviando más rápido de lo que la línea aguanta.',
        arreglo: 'Pausa, baja la velocidad de envío en el paso 4 y reanuda. Empezar por 60 por minuto es prudente.',
    },
];

function TabFallos({ onIr }) {
    return (
        <div className="space-y-5">
            <Lead>
                Cada fallo aparece explicado en la fila del destinatario, con el motivo en castellano. Estos son los cinco
                más habituales.
            </Lead>

            {FALLOS.map((f, i) => (
                <Section key={i} title={f.sintoma}>
                    <p className="text-muted-foreground"><strong className="text-foreground">Por qué:</strong> {f.causa}</p>
                    <p className="text-muted-foreground"><strong className="text-foreground">Qué hacer:</strong> {f.arreglo}</p>
                </Section>
            ))}

            <Note tono="teal">
                Cuando arregles la causa, no hace falta rehacer la campaña: en el detalle hay un botón para
                <strong> reintentar solo los fallidos</strong>. Los que ya llegaron no se vuelven a enviar.
                Los estados están explicados en <button onClick={() => onIr('estados')} className="underline">Los estados</button>.
            </Note>
        </div>
    );
}

function TabDetalles() {
    return (
        <div className="space-y-5">
            <Section title="Marketing y utilidad no son lo mismo">
                <p className="text-muted-foreground">
                    Meta clasifica cada plantilla. Las de <Field>utilidad</Field> acompañan algo que el cliente ya tiene con
                    tu empresa (una factura, un corte, un soporte) y se entregan con más holgura. Las de
                    <Field>marketing</Field> son promocionales: cuestan más y algunos clientes pueden no recibirlas.
                    Mandar un aviso operativo con una plantilla de marketing es pagar de más y llegar a menos gente.
                </p>
            </Section>

            <Section title="Calidad de la línea y límites de envío">
                <p className="text-muted-foreground">
                    WhatsApp vigila cuánta gente bloquea o reporta tu número. Si baja la calidad, baja también cuántos
                    clientes distintos puedes contactar al día. Enviar a gente que no espera nada de ti es la forma más
                    rápida de quemar la línea; enviar avisos útiles, la forma de subir el límite.
                </p>
            </Section>

            <Section title="Velocidad de envío">
                <p className="text-muted-foreground">
                    La campaña no se manda de golpe: se escalona según la velocidad que elijas (60 mensajes por minuto por
                    defecto). Si Meta empieza a rechazar por exceso, pausa y baja el ritmo.
                </p>
            </Section>

            <Section title="Quien pide no recibir campañas">
                <p className="text-muted-foreground">
                    En <Field>Contactos</Field> cada ficha tiene un interruptor para excluirla de las campañas. Es la baja de
                    los envíos masivos, no del servicio: al cliente se le sigue respondiendo en el chat y le siguen llegando
                    los avisos que dispara el ERP (facturas, cortes, soportes). Si aun así lo seleccionas en una campaña,
                    aparecerá como <Field>omitido</Field> en el detalle, para que se vea cuánta gente quedó fuera y por qué.
                    Respetarlo no es solo cortesía: cada reporte de un cliente molesto baja la calidad de la línea.
                </p>
            </Section>

            <Section title="Archivos del encabezado">
                <p className="text-muted-foreground">
                    Imagen JPG o PNG hasta 5 MB, video MP4 hasta 16 MB, PDF hasta 100 MB. El archivo se sube una vez por
                    campaña. Ten en cuenta que WhatsApp borra los archivos subidos a los 30 días: para relanzar una campaña
                    vieja habrá que volver a subirlo.
                </p>
            </Section>

            <Note tono="amber">
                <strong>Antes de enviar a miles:</strong> prueba la campaña contigo mismo o con dos compañeros. La vista
                previa es fiel, pero un dato mal mapeado solo se ve del todo en un teléfono de verdad.
            </Note>
        </div>
    );
}

/* ── Piezas ── */

function Lead({ children }) {
    return <p className="text-muted-foreground">{children}</p>;
}

function Section({ title, children }) {
    return (
        <section className="space-y-1.5">
            <h3 className="text-xs font-semibold uppercase tracking-wider text-foreground">{title}</h3>
            {children}
        </section>
    );
}

function Field({ children }) {
    return <span className="rounded bg-muted px-1.5 py-0.5 text-[12px] font-medium text-foreground mx-0.5">{children}</span>;
}

function Punto({ ok }) {
    return <span className={`size-2 rounded-full shrink-0 ${ok ? 'bg-emerald-500' : 'bg-amber-500'}`} />;
}

function Note({ tono = 'teal', children }) {
    const clases = {
        teal: 'border-teal-300 bg-teal-50 text-teal-900 dark:bg-teal-900/20 dark:border-teal-800 dark:text-teal-100',
        amber: 'border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-100',
    }[tono];

    return (
        <div className={`rounded-xl border px-4 py-3 flex gap-3 ${clases}`}>
            {tono === 'amber' ? <AlertTriangle className="size-4 shrink-0 mt-0.5" /> : <Gauge className="size-4 shrink-0 mt-0.5" />}
            <div>{children}</div>
        </div>
    );
}
