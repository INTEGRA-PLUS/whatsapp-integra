import { useState } from 'react';
import {
    ArrowRight, MessageCircle, Zap, Hand, CheckCheck, AlertTriangle,
    Plug, Clock, X, ListTree,
} from 'lucide-react';
import {
    GROUP_LABELS, GROUP_ORDER, iconFor, ACTION_HELP,
    MATCH_OPTIONS, MATCH_HELP, SILENCE_REASONS, TEMPLATE_VARS, OFFLINE_BEHAVIOUR,
} from './catalog';

/**
 * La ayuda del módulo: cómo se crea un menú y qué hace cada pieza.
 *
 * Se arma con los mismos datos que alimentan el formulario —el catálogo de
 * acciones y los límites vienen del backend— en vez de con texto escrito a
 * mano. Una ayuda con la lista de acciones copiada se queda vieja en cuanto se
 * conecta una integración nueva, y una ayuda desactualizada es peor que no
 * tener ninguna: el admin la lee, hace lo que dice y no le funciona.
 */

const TABS = [
    { id: 'flujo', label: 'Cómo funciona' },
    { id: 'crear', label: 'Crear un menú' },
    { id: 'acciones', label: 'Las acciones' },
    { id: 'fallos', label: 'Si algo falla' },
    { id: 'detalles', label: 'Detalles' },
];

export default function MenuHelp({ actionTypes = [], limits = {}, statusSegments = [], integra = {}, menus = [], onClose }) {
    const [tab, setTab] = useState('flujo');

    return (
        <div className="space-y-5">
            <nav className="flex flex-wrap gap-1 border-b -mt-1">
                {TABS.map(t => (
                    <button
                        key={t.id}
                        type="button"
                        onClick={() => setTab(t.id)}
                        className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
                            tab === t.id
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        {t.label}
                    </button>
                ))}
            </nav>

            <div className="max-h-[60vh] overflow-y-auto pr-1 space-y-6">
                {tab === 'flujo' && <FlowTab />}
                {tab === 'crear' && <CreateTab limits={limits} menus={menus} />}
                {tab === 'acciones' && <ActionsTab actionTypes={actionTypes} statusSegments={statusSegments} onTrouble={() => setTab('fallos')} />}
                {tab === 'fallos' && <TroubleTab integra={integra} />}
                {tab === 'detalles' && <DetailsTab limits={limits} />}
            </div>

            <div className="flex justify-end border-t pt-4">
                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex h-9 items-center gap-2 rounded-md border border-input px-4 text-sm font-medium hover:bg-accent"
                >
                    <X className="size-4" /> Cerrar
                </button>
            </div>
        </div>
    );
}

/* ── Cómo funciona ─────────────────────────────────────────────── */

const FLOW = [
    { icon: MessageCircle, title: 'El cliente escribe', text: 'Manda un mensaje a tu línea de WhatsApp, o toca una opción de un menú anterior.' },
    { icon: Zap, title: 'Se dispara el menú', text: 'Si el mensaje cumple alguna de las condiciones que definiste, el cliente recibe el menú con sus opciones.' },
    { icon: Hand, title: 'El cliente elige', text: 'Toca una opción, o escribe el número o el título. Las dos formas se entienden durante la hora siguiente.' },
    { icon: CheckCheck, title: 'Pasa lo que configuraste', text: 'Un mensaje, otro menú, una consulta a tu software, o el chat pasa a un asesor.' },
];

function FlowTab() {
    return (
        <>
            <Lead>
                Un menú convierte «buenas, necesito ayuda» en un toque sobre un botón. El cliente elige,
                el sistema responde en segundos, y a tu equipo sólo le llega lo que de verdad necesita
                a una persona.
            </Lead>

            <ol className="space-y-0">
                {FLOW.map((step, i) => (
                    <li key={step.title} className="grid grid-cols-[32px_minmax(0,1fr)] gap-3 relative pb-5 last:pb-0">
                        {i < FLOW.length - 1 && (
                            <span className="absolute left-[15.5px] top-9 bottom-1 w-px bg-border" aria-hidden="true" />
                        )}
                        <span className="relative z-10 flex size-8 items-center justify-center rounded-full border bg-background">
                            <step.icon className="size-4 text-muted-foreground" />
                        </span>
                        <div className="pt-1">
                            <p className="text-sm font-medium text-foreground">{step.title}</p>
                            <p className="text-[13px] text-muted-foreground mt-0.5">{step.text}</p>
                        </div>
                    </li>
                ))}
            </ol>

        </>
    );
}

/* ── Crear un menú ─────────────────────────────────────────────── */

function CreateTab({ limits, menus }) {
    const steps = [
        {
            title: 'Ponle nombre y elige la línea',
            body: (
                <>
                    El <Field>Nombre</Field> es sólo para ti: el cliente nunca lo ve. En <Field>Instancia</Field>{' '}
                    eliges a qué línea de WhatsApp pertenece, o <Field>Todas las instancias</Field> si vale
                    para cualquiera. El menú de una línea concreta le gana al genérico.
                </>
            ),
        },
        {
            title: 'Define cuándo aparece',
            body: (
                <>
                    Marca una o varias condiciones. Si eliges alguna de palabra clave, escríbelas separadas
                    por comas: no distinguen mayúsculas ni tildes. Marca <Field>Es un submenú</Field> si este
                    menú sólo debe abrirse desde una opción de otro.
                </>
            ),
        },
        {
            title: 'Escribe el mensaje',
            body: (
                <>
                    El <Field>Cuerpo</Field> es obligatorio y acompaña a las opciones. El{' '}
                    <Field>Encabezado</Field> y el <Field>Pie</Field> son opcionales y cortos
                    ({limits.max_header ?? 60} caracteres): sirven para el nombre de la empresa arriba y una
                    aclaración pequeña abajo.
                </>
            ),
        },
        {
            title: 'Añade las opciones',
            body: (
                <>
                    Cada una necesita un <strong>título</strong> —lo que el cliente toca— y una{' '}
                    <strong>acción</strong> —lo que pasa al tocarlo—. Las reordenas con las flechas y las
                    quitas con la ✕. Empieza el título por el verbo y ponle un emoji delante: ancla la vista
                    y casi no gasta espacio.
                </>
            ),
        },
        {
            title: 'Revisa la vista previa y guarda',
            body: (
                <>
                    A la derecha ves el menú tal como le va a llegar al cliente, con los títulos ya recortados
                    si se pasan de largo. Cuando te convenza, marca <Field>Menú activo</Field> y guarda: ése
                    es el momento en que empieza a responder.
                </>
            ),
        },
    ];

    const root = menus.find(m => m.is_root);
    const pending = pendingSetup(menus);

    return (
        <>
            {root ? (
                <div className="rounded-lg border bg-muted/30 p-4 space-y-3">
                    <div className="flex items-start gap-2.5">
                        <ListTree className="size-4 shrink-0 mt-0.5 text-muted-foreground" />
                        <div>
                            <p className="text-sm font-medium text-foreground">
                                No empiezas de cero: ya tienes «{root.name}»
                            </p>
                            <p className="text-[13px] text-muted-foreground mt-0.5">
                                {root.active
                                    ? 'Está encendido y respondiendo. Los pasos de abajo te sirven para revisarlo o para crear otro.'
                                    : 'Está creado y apagado, esperando a que lo revises. Los pasos de abajo recorren todos sus campos; cuando te convenza, enciéndelo.'}
                            </p>
                        </div>
                    </div>

                    {pending.length > 0 && (
                        <div className="rounded-md bg-amber-50 dark:bg-amber-900/20 px-3 py-2.5">
                            <p className="text-[12px] font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400 mb-1.5">
                                Te falta completar {pending.length === 1 ? 'una cosa' : `${pending.length} cosas`}
                            </p>
                            <ul className="space-y-1">
                                {pending.map(item => (
                                    <li key={item} className="flex gap-2 text-[13px] text-amber-700 dark:text-amber-400">
                                        <AlertTriangle className="size-3.5 shrink-0 mt-0.5" />
                                        <span>{item}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {pending.length === 0 && (
                        <p className="flex items-start gap-2 rounded-md bg-teal-50 dark:bg-teal-900/20 px-3 py-2.5 text-[13px] text-teal-700 dark:text-teal-400">
                            <CheckCheck className="size-4 shrink-0 mt-0.5" />
                            <span>No le falta nada por configurar. Revísalo y {root.active ? 'listo' : 'enciéndelo'}.</span>
                        </p>
                    )}
                </div>
            ) : (
                <Lead>
                    Cinco pasos. El formulario valida mientras escribes y la vista previa se actualiza sola,
                    así que puedes ir viendo el resultado sin guardar nada.
                </Lead>
            )}

            <ol className="space-y-0">
                {steps.map((step, i) => (
                    <li key={step.title} className="grid grid-cols-[28px_minmax(0,1fr)] gap-3 relative pb-5 last:pb-0">
                        {i < steps.length - 1 && (
                            <span className="absolute left-[13.5px] top-8 bottom-1 w-px bg-border" aria-hidden="true" />
                        )}
                        <span className="relative z-10 flex size-7 items-center justify-center rounded-full border bg-background text-[12px] font-semibold text-primary">
                            {i + 1}
                        </span>
                        <div className="pt-0.5">
                            <p className="text-sm font-medium text-foreground">{step.title}</p>
                            <p className="text-[13px] text-muted-foreground mt-1 leading-relaxed">{step.body}</p>
                        </div>
                    </li>
                ))}
            </ol>

            <Note tone="teal" icon={Clock}>
                <strong>Espera entre envíos.</strong> Evita que el cliente reciba el mismo menú cinco veces
                si escribe cinco mensajes seguidos. Viene en 60 minutos; en 0 se reenvía cada vez que se
                cumpla la condición.
            </Note>

            <Section title="Pruébalo antes de encenderlo">
                <p className="text-[13px] text-muted-foreground">
                    Escríbele a tu propia línea desde un teléfono personal. El sistema te trata como a
                    cualquier otro cliente, así que ves el menú exactamente como lo verá la gente.
                </p>
            </Section>
        </>
    );
}

/**
 * Lo que le falta a los menús de esta empresa para funcionar de verdad.
 *
 * Se calcula de los menús reales y no se escribe a mano: "configuré la opción y
 * no pasa nada" casi siempre es uno de estos campos vacíos, y decírselo al
 * admin con el nombre de la opción delante le ahorra buscar cuál era.
 *
 * @returns {string[]}
 */
function pendingSetup(menus) {
    const missing = [];

    for (const menu of menus) {
        for (const option of menu.options ?? []) {
            const config = option.config ?? {};
            const where = `«${option.title}»`;

            if (option.action_type === 'reportar_falla' && !config.radicado_servicio) {
                missing.push(`Elige el tipo de falla en ${where}, o no podrá crear el radicado.`);
            }

            if (option.action_type === 'pagar_en_linea' && !config.payment_url) {
                missing.push(`Pega el enlace de pago en ${where}, o el cliente sabrá cuánto debe pero no dónde pagarlo.`);
            }

            if (option.action_type === 'submenu' && !option.target_menu_id) {
                missing.push(`Elige el submenú al que lleva ${where}.`);
            }

            if (option.action_type === 'none') {
                missing.push(`Decide qué hace ${where}: hoy el cliente la toca y no recibe nada.`);
            }
        }
    }

    return missing;
}

/* ── Las acciones ──────────────────────────────────────────────── */

function ActionsTab({ actionTypes, statusSegments, onTrouble }) {
    const grouped = GROUP_ORDER
        .map(group => [group, actionTypes.filter(a => a.group === group)])
        .filter(([, list]) => list.length > 0);

    return (
        <>
            <Lead>
                Lo que ocurre cuando el cliente toca una opción. Las de autoservicio consultan tu software
                y responden con datos reales; las demás se resuelven con lo que escribas aquí.
            </Lead>

            {grouped.map(([group, list]) => (
                <Section key={group} title={GROUP_LABELS[group] ?? group}>
                    <div className="space-y-3">
                        {list.map(action => {
                            const Icon = iconFor(action.value);
                            const help = ACTION_HELP[action.value];

                            return (
                                <div key={action.value} className="rounded-lg border bg-muted/30 p-3">
                                    <div className="flex items-center gap-2 mb-1.5">
                                        <Icon className="size-4 shrink-0 text-muted-foreground" />
                                        <span className="text-sm font-medium text-foreground">{action.label}</span>
                                    </div>
                                    <p className="text-[13px] text-muted-foreground leading-relaxed">
                                        {help?.does ?? 'Sin descripción disponible todavía.'}
                                    </p>
                                    {help?.needs && (
                                        <p className="text-[12px] text-muted-foreground/80 mt-2 flex gap-1.5">
                                            <ArrowRight className="size-3.5 shrink-0 mt-0.5" />
                                            <span><strong className="font-medium">Tienes que definir:</strong> {help.needs}</span>
                                        </p>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </Section>
            ))}

            {statusSegments.length > 0 && (
                <Section title="Partes del contrato">
                    <p className="text-[13px] text-muted-foreground mb-3">
                        Una sola consulta trae todo el contrato, así que segmentarlo no cuesta llamadas.
                        Para partirlo, crea un submenú con una opción por cada parte:
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                        {statusSegments.map(seg => (
                            <span key={seg.value} className="rounded-md border bg-background px-2 py-1 text-[12px] text-muted-foreground">
                                {seg.label}
                            </span>
                        ))}
                    </div>
                </Section>
            )}

            <Note tone="teal" icon={Plug}>
                ¿Qué pasa con estas cuatro si tu software no está conectado, o si se cae?{' '}
                <button type="button" onClick={onTrouble} className="underline underline-offset-2 font-medium">
                    Está explicado en «Si algo falla»
                </button>.
            </Note>
        </>
    );
}

/* ── Si algo falla ─────────────────────────────────────────────── */

/**
 * Todo lo que puede ir mal, junto y en su propia pestaña.
 *
 * Estaba repartido —las razones del silencio en "Cómo funciona", el software
 * caído al fondo de "Las acciones"— y era justo lo que nadie encontraba. Quien
 * abre la ayuda con un problema delante no va a recorrer cuatro pestañas: o lo
 * ve en la que dice "Si algo falla", o se rinde y escribe a soporte.
 */
function TroubleTab({ integra }) {
    return (
        <>
            <Lead>
                Casi nada de lo que parece un fallo lo es. Esto es lo que pasa en cada caso y qué mirar.
            </Lead>

            <Note tone={integra.connected ? 'teal' : 'amber'} icon={Plug}>
                {integra.connected
                    ? 'Tu software está conectado ahora mismo: las cuatro acciones de autoservicio responden con datos reales.'
                    : 'Tu software NO está conectado ahora mismo: las cuatro acciones de autoservicio derivarán el chat a un asesor. Se conecta desde Integraciones.'}
            </Note>

            <Section title="El menú no le llegó al cliente">
                <p className="text-[13px] text-muted-foreground mb-3">
                    Casi nunca es un error. Estas son las razones, de más a menos frecuente:
                </p>
                <ol className="space-y-1.5">
                    {SILENCE_REASONS.map((reason, i) => (
                        <li key={reason} className="flex gap-2.5 text-[13px] text-muted-foreground">
                            <span className="shrink-0 font-mono text-[11px] text-muted-foreground/60 pt-0.5">{i + 1}</span>
                            <span>{reason}</span>
                        </li>
                    ))}
                </ol>
            </Section>

            <Section title="Tu software no está conectado, o se cayó">
                <p className="text-[13px] text-muted-foreground mb-3">
                    Las cuatro acciones de autoservicio lo consultan. Esto es exactamente lo que pasa
                    mientras no esté disponible:
                </p>
                <div className="space-y-2">
                    {OFFLINE_BEHAVIOUR.map(item => (
                        <div key={item.title} className="flex gap-2.5 rounded-lg border bg-muted/30 p-3">
                            <CheckCheck className="size-4 shrink-0 mt-0.5 text-muted-foreground" />
                            <div>
                                <p className="text-[13px] font-medium text-foreground">{item.title}</p>
                                <p className="text-[13px] text-muted-foreground mt-0.5">{item.text}</p>
                            </div>
                        </div>
                    ))}
                </div>
                <Note tone="teal" icon={Clock} className="mt-3">
                    <strong>No hace falta apagar el menú</strong> mientras conectas el software. Las opciones
                    de autoservicio se comportan como un «hablar con un asesor» y empiezan a responder con
                    datos reales en cuanto conectes, sin tocar nada más.
                </Note>
            </Section>

            <Section title="Unas opciones funcionan y otra no">
                <p className="text-[13px] text-muted-foreground">
                    Si «Consultar factura» responde bien pero otra dice <em>«tuve un problema consultando tu
                    información»</em> y deriva a un asesor, la conexión está bien: lo que falla es un{' '}
                    <strong>permiso concreto del acceso</strong>. Cada consulta pide el suyo, y el acceso que
                    se generó puede no incluirlos todos.
                </p>
                <Note tone="amber" icon={AlertTriangle} className="mt-3">
                    No hay nada que arreglar en el menú. Pásaselo a tu área de sistemas: el acceso a tu
                    software necesita un permiso más.
                </Note>
            </Section>

            <Section title="«Reportar falla» no crea el radicado">
                <p className="text-[13px] text-muted-foreground">
                    Dos causas posibles. La primera: le falta el <strong>tipo de falla</strong>, y el
                    formulario te lo avisa en amarillo. La segunda: el servicio del cliente está{' '}
                    <strong>suspendido por mora</strong>, y entonces no crea radicado a propósito — se lo dice
                    al cliente y le ofrece pagar, que es lo que de verdad resuelve su problema.
                </p>
            </Section>

            <Section title="El cliente recibió dos respuestas">
                <p className="text-[13px] text-muted-foreground">
                    No debería pasar: cuando un menú se hace cargo de un mensaje, la respuesta automática no
                    se envía. Si lo ves, avisa — es un fallo de verdad.
                </p>
            </Section>
        </>
    );
}

/* ── Detalles ──────────────────────────────────────────────────── */

function DetailsTab({ limits }) {
    const rows = [
        ['Título de la opción', limits.max_row_title ?? 24, `En formato de botones sólo se ven ${limits.max_button_title ?? 20}.`],
        ['Descripción de la opción', limits.max_row_description ?? 72, 'Sólo visible cuando el menú sale como lista.'],
        ['Cuerpo del mensaje', limits.max_body ?? 1024, 'De sobra para cualquier menú razonable.'],
        ['Encabezado y pie', limits.max_header ?? 60, 'Cada uno.'],
        ['Botón que abre la lista', limits.max_button_title ?? 20, 'Por defecto: «Ver opciones».'],
    ];

    return (
        <>
            <Section title="Botones o lista: lo decide la cantidad">
                <p className="text-[13px] text-muted-foreground">
                    Con <strong>{limits.max_buttons ?? 3} opciones o menos</strong> el menú sale como botones,
                    que el cliente toca de una. Con <strong>4 o más</strong> sale como lista: aparece un solo
                    botón y al tocarlo se despliegan todas. El máximo son {limits.max_rows ?? 10} opciones.
                    No lo eliges tú: son los dos únicos formatos que WhatsApp acepta y cada uno tiene su tope.
                </p>
            </Section>

            <Section title="Cuándo aparece">
                <div className="space-y-2">
                    {MATCH_OPTIONS.map(opt => (
                        <div key={opt.value} className="rounded-lg border bg-muted/30 p-3">
                            <p className="text-sm font-medium text-foreground">{opt.label}</p>
                            <p className="text-[13px] text-muted-foreground mt-0.5">{MATCH_HELP[opt.value]?.when}</p>
                            <p className="text-[12px] text-muted-foreground/70 mt-1.5">
                                Ejemplo: «{MATCH_HELP[opt.value]?.example}»
                            </p>
                        </div>
                    ))}
                </div>
                <Note tone="amber" icon={AlertTriangle} className="mt-3">
                    Sólo puede haber un menú de bienvenida por línea. Si ya existe uno, la opción aparece en
                    gris: con dos activos, el cliente recibiría uno u otro sin ninguna lógica.
                </Note>
            </Section>

            <Section title="Límites de caracteres">
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="border-b bg-muted/40">
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Campo</th>
                                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Máx.</th>
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Aviso</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map(([field, max, note]) => (
                                <tr key={field} className="border-b last:border-0">
                                    <td className="px-3 py-2 text-foreground">{field}</td>
                                    <td className="px-3 py-2 text-right tabular-nums text-muted-foreground">{max}</td>
                                    <td className="px-3 py-2 text-muted-foreground">{note}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="text-[12px] text-muted-foreground mt-2">
                    Lo que se pase se recorta al enviar, para que el menú no falle. La vista previa te lo
                    muestra ya recortado.
                </p>
            </Section>

            <Section title="Variables">
                <p className="text-[13px] text-muted-foreground mb-3">
                    Funcionan en el cuerpo, el encabezado, el pie y los textos de respuesta. Se reemplazan
                    por los datos de cada cliente al enviar:
                </p>
                <div className="space-y-1.5">
                    {TEMPLATE_VARS.map(v => (
                        <div key={v.token} className="flex flex-wrap items-baseline gap-2 text-[13px]">
                            <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[12px] text-foreground">{v.token}</code>
                            <span className="text-muted-foreground">{v.is}</span>
                        </div>
                    ))}
                </div>
                <Note tone="amber" icon={AlertTriangle} className="mt-3">
                    <code className="font-mono">{'{name}'}</code> es el nombre que el cliente puso en <em>su</em>{' '}
                    perfil de WhatsApp. A veces es «Mamá ❤️» o un apodo: sirve para saludar, no para donde
                    necesites el nombre real del titular.
                </Note>
            </Section>

            <Section title="Si el cliente escribe en vez de tocar">
                <p className="text-[13px] text-muted-foreground">
                    Mucha gente responde «1», «2.» o copia el título de la opción. El sistema lo entiende
                    igual durante la hora siguiente al envío. Pasado ese rato, un «1» suelto vuelve a ser un
                    mensaje normal, porque a esas alturas el cliente ya ni recuerda el menú.
                </p>
            </Section>

            <Section title="Editar un menú que ya está en la calle">
                <p className="text-[13px] text-muted-foreground">
                    Puedes cambiar títulos, textos y acciones cuando quieras. Los menús ya enviados siguen
                    funcionando: si un cliente abre una conversación de hace días y toca una opción, el
                    sistema la reconoce y ejecuta lo que esa opción hace <em>ahora</em>.
                </p>
            </Section>
        </>
    );
}

/* ── Piezas ────────────────────────────────────────────────────── */

function Lead({ children }) {
    return <p className="text-[13.5px] leading-relaxed text-muted-foreground">{children}</p>;
}

function Section({ title, children }) {
    return (
        <section className="space-y-2">
            <h3 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{title}</h3>
            {children}
        </section>
    );
}

function Field({ children }) {
    return <span className="rounded bg-muted px-1.5 py-0.5 text-[12px] font-medium text-foreground">{children}</span>;
}

const NOTE_TONES = {
    amber: 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400',
    teal: 'bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-400',
};

function Note({ tone = 'amber', icon: Icon, className = '', children }) {
    return (
        <p className={`flex items-start gap-2 rounded-lg px-3 py-2.5 text-[13px] leading-relaxed ${NOTE_TONES[tone]} ${className}`}>
            {Icon && <Icon className="size-4 shrink-0 mt-0.5" />}
            <span>{children}</span>
        </p>
    );
}
