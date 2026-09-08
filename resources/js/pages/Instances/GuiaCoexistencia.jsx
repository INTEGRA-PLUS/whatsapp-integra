import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { ArrowLeft, CheckCircle2, Info, Monitor, Smartphone, TriangleAlert } from 'lucide-react';

/**
 * La guía de conexión por coexistencia, dentro del producto.
 *
 * Está aquí y no en un PDF porque el cliente la necesita en el momento en que
 * está mirando el botón, no en un archivo que alguien le mandó por correo la
 * semana pasada.
 *
 * La estructura sigue lo que de verdad complica este proceso: la mitad de los
 * pasos ocurren en el navegador y la otra mitad en el celular del cliente. Cada
 * paso lleva su etiqueta para que quien lo ejecuta sepa dónde tiene que estar
 * mirando; perder ese hilo es lo que hace que la gente abandone a la mitad.
 */

const IMG = '/img/coexistencia';

function Etiqueta({ donde }) {
    const esCelular = donde === 'celular';
    const Icono = esCelular ? Smartphone : Monitor;

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[10.5px] font-bold uppercase tracking-wider ${
            esCelular
                ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                : 'bg-blue-500/15 text-blue-700 dark:text-blue-400'
        }`}>
            <Icono className="size-3" />
            {esCelular ? 'Celular' : 'Escritorio'}
        </span>
    );
}

function Aviso({ tono = 'info', titulo, children }) {
    const estilos = {
        info:  'border-blue-500/30 bg-blue-500/10 text-blue-700 dark:text-blue-400',
        ojo:   'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
        alto:  'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-400',
        bien:  'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    }[tono];

    const Icono = { info: Info, ojo: TriangleAlert, alto: TriangleAlert, bien: CheckCircle2 }[tono];

    return (
        <div className={`rounded-xl border px-4 py-3 ${estilos}`}>
            <p className="flex items-center gap-2 text-[13px] font-bold">
                <Icono className="size-4 shrink-0" />
                {titulo}
            </p>
            <div className="mt-1.5 space-y-1.5 text-[13px] leading-relaxed opacity-90 [&_strong]:font-semibold">
                {children}
            </div>
        </div>
    );
}

/** Una captura con su explicación. Las del celular van más estrechas. */
function Captura({ src, alt, pie, celular = false }) {
    return (
        <figure className="flex flex-col gap-2.5 rounded-xl border bg-card p-3 shadow-xs">
            <img
                src={`${IMG}/${src}`}
                alt={alt}
                loading="lazy"
                className={`w-full rounded-lg border bg-muted/30 ${celular ? 'mx-auto max-w-[240px]' : ''}`}
            />
            <figcaption className="px-0.5 text-[12.5px] leading-snug text-muted-foreground [&_strong]:font-semibold [&_strong]:text-foreground">
                {pie}
            </figcaption>
        </figure>
    );
}

function Paso({ n, donde, titulo, ruta, children }) {
    return (
        <section className="grid gap-x-7 gap-y-4 border-t py-8 sm:grid-cols-[110px_minmax(0,1fr)]">
            <div className="flex items-center gap-3 sm:flex-col sm:items-start">
                <span className="text-4xl font-bold leading-none tabular-nums text-foreground">{n}</span>
                <Etiqueta donde={donde} />
            </div>
            <div className="min-w-0">
                <h2 className="text-lg font-semibold text-foreground">{titulo}</h2>
                {ruta && (
                    <p className="mt-1 font-mono text-[12px] leading-relaxed text-muted-foreground">{ruta}</p>
                )}
                <div className="mt-3 space-y-4 text-[14px] leading-relaxed text-foreground/90 [&_strong]:font-semibold">
                    {children}
                </div>
            </div>
        </section>
    );
}

export default function GuiaCoexistencia() {
    return (
        <>
            <Head title="Conectar tu WhatsApp Business" />
            <div className="mx-auto flex max-w-4xl flex-col gap-6 p-6 pb-20">

                <div>
                    <Link
                        href="/instances"
                        className="inline-flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" /> Volver a Instancias
                    </Link>
                    <h1 className="mt-3 text-2xl font-semibold text-foreground">
                        Conectar el número que ya usas en WhatsApp Business
                    </h1>
                    <p className="mt-2 max-w-2xl text-[14px] leading-relaxed text-muted-foreground">
                        Sin perder tus chats y sin dejar de responder desde el celular. Toma unos 15 minutos
                        y necesitas el teléfono a la mano.
                    </p>
                </div>

                <Aviso tono="info" titulo="Antes de empezar, comprueba tres cosas">
                    <p>El número usa la app de <strong>WhatsApp Business</strong>, no la de WhatsApp normal.</p>
                    <p>Lleva <strong>más de una semana</strong> en uso: Meta pide actividad real antes de aceptarlo.</p>
                    <p>Tienes el <strong>celular a la mano</strong>, con cámara y batería.</p>
                </Aviso>

                <Aviso tono="ojo" titulo="Qué cambia en tu celular">
                    <p>Sigues respondiendo desde la app y no pierdes ningún chat. Pero se <strong>desconectan
                    los dispositivos vinculados</strong> —WhatsApp Web incluido— durante el proceso, las
                    <strong> listas de difusión</strong> quedan de solo lectura, y se desactivan los
                    <strong> mensajes temporales</strong> y los de <strong>ver una vez</strong> en los chats
                    individuales. Los <strong>grupos</strong> siguen funcionando en tu celular pero no entran aquí.</p>
                </Aviso>

                <div className="mt-2">
                    <Paso
                        n="1"
                        donde="escritorio"
                        titulo="Vincula tu cuenta en Meta Business Suite"
                        ruta="Meta Business Suite → tu portafolio → Cuentas de WhatsApp → Agregar → Vincular una cuenta de WhatsApp Business"
                    >
                        <p>
                            Este es el paso que más se salta, y sin él nada de lo demás funciona: la ventana
                            de conexión rechazará tu número diciendo que ya está registrado.
                        </p>

                        <Aviso tono="alto" titulo="No confundas las tres opciones">
                            <p>
                                <strong>Vincular una cuenta de WhatsApp Business</strong> es la correcta: toma la
                                cuenta que ya existe en tu celular. <em>Crear una nueva</em> genera una cuenta vacía
                                para un número virgen, y <em>Solicitar una para un cliente</em> es para otro negocio.
                            </p>
                        </Aviso>

                        <Captura
                            src="01-menu-agregar.jpg"
                            alt="Menú Agregar con las tres opciones de cuenta de WhatsApp"
                            pie={<>Las tres opciones del botón <strong>Agregar</strong>. La de abajo es la que sirve.</>}
                        />

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Captura
                                src="02-cuenta-vinculada.jpg"
                                alt="Confirmación de que la cuenta quedó vinculada"
                                pie={<>Meta confirma la vinculación. Pulsa <strong>Listo</strong>.</>}
                            />
                            <Captura
                                src="03-activo-portafolio.jpg"
                                alt="La cuenta aparece en el portafolio con el subtítulo App de WhatsApp Business"
                                pie={<>Queda en tu portafolio con el subtítulo <strong>App de WhatsApp Business</strong>.</>}
                            />
                        </div>

                        <Captura
                            src="04-numero-sin-conexion.jpg"
                            alt="Pestaña de números de teléfono con el estado Sin conexión"
                            pie={<>En <strong>Números de teléfono</strong> aparecerá como <strong>Sin conexión</strong>. Es lo normal: todavía no está conectado aquí.</>}
                        />
                    </Paso>

                    <Paso
                        n="2"
                        donde="escritorio"
                        titulo="Abre la ventana de conexión"
                        ruta="Integra CRM → Instancias → Conectar mi WhatsApp Business actual"
                    >
                        <p>
                            Se abre una ventana emergente de Facebook. Si no aparece, tu navegador la está
                            bloqueando: permite las ventanas emergentes para este sitio y vuelve a intentar.
                        </p>
                        <p>La primera pantalla es informativa. Pulsa <strong>Continuar</strong>.</p>
                    </Paso>

                    <Paso
                        n="3"
                        donde="escritorio"
                        titulo="Escribe tu número a mano"
                        ruta="Deja «Enter a new phone number» → elige el país → escribe el número"
                    >
                        <Aviso tono="ojo" titulo="Aquí es donde casi todos se equivocan">
                            <p>
                                Parece lógico buscar tu número en la lista desplegable, pero <strong>no está ahí</strong>:
                                esa lista sólo trae números que ya están en la API. Deja
                                <em> Enter a new phone number</em> y <strong>escríbelo</strong>.
                            </p>
                            <p>
                                Al escribirlo, Meta detecta que ese número está en uso en tu app de WhatsApp
                                Business y cambia solo al proceso correcto. No hay ningún botón que pulsar.
                            </p>
                        </Aviso>

                        <Captura
                            src="05-qr-importar.jpg"
                            alt="Pantalla de importar contactos e historial con un código QR"
                            pie={<>Si ves esta pantalla, vas bien. A partir de aquí el proceso sigue <strong>en tu celular</strong>. No cierres esta ventana.</>}
                        />
                    </Paso>

                    <Paso
                        n="4"
                        donde="celular"
                        titulo="Autoriza desde tu teléfono"
                        ruta="Cinco pantallas seguidas. Ten la ventana del computador a la vista: hay que escanear el código."
                    >
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Captura celular src="06-mensaje-facebook.jpg" alt="Mensaje de Facebook Business con el botón Connect"
                                pie={<><strong>1.</strong> Te llega un mensaje de <strong>Facebook Business</strong>. Toca <strong>Connect</strong>.</>} />
                            <Captura celular src="07-conectate-plataforma.jpg" alt="Pantalla Conéctate a la plataforma para empresas"
                                pie={<><strong>2.</strong> Toca <strong>Conéctate a la plataforma para empresas</strong>.</>} />
                            <Captura celular src="08-comparte-historial.jpg" alt="Pantalla para compartir el historial de chats"
                                pie={<><strong>3.</strong> Elige <strong>Compartir todos los chats</strong> y confirma.</>} />
                        </div>

                        <Aviso tono="ojo" titulo="Esta decisión no se puede cambiar después">
                            <p>
                                Si eliges <em>No compartir chats</em>, no se importará nada y tendrás que empezar
                                conversaciones nuevas con tus clientes actuales.
                            </p>
                        </Aviso>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <Captura celular src="09-escanea-qr.jpg" alt="Pantalla para escanear el código QR"
                                pie={<><strong>4.</strong> Toca <strong>OK</strong> y apunta la cámara al código de tu computador.</>} />
                            <Captura celular src="10-plataforma-conectada.jpg" alt="Pantalla Plataforma conectada"
                                pie={<><strong>5.</strong> Aparece <strong>Plataforma conectada</strong>. Aún dice que falta configurar: es normal.</>} />
                            <Captura celular src="11-te-conectaste.jpg" alt="Confirmación de conexión"
                                pie="Y la confirmación de que tu historial se está compartiendo." />
                        </div>

                        <Aviso tono="info" titulo="Si la cámara no lee el código">
                            <p>Abajo en esa pantalla hay un enlace <strong>Ingresa el código de acceso</strong>. La ventana del computador te muestra ese código como alternativa.</p>
                        </Aviso>
                    </Paso>

                    <Paso
                        n="5"
                        donde="escritorio"
                        titulo="Confirma tu cuenta"
                        ruta="Vuelve a la ventana del computador, que habrá avanzado sola"
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Captura src="12-confirma-cuenta.jpg" alt="Pantalla para confirmar o editar la cuenta"
                                pie={<>Revisa el <strong>nombre</strong> —es el que verán tus clientes— y la <strong>zona horaria</strong>.</>} />
                            <Captura src="13-revisa-permisos.jpg" alt="Pantalla con el resumen de permisos"
                                pie={<>El resumen de lo que compartes. Pulsa <strong>Confirmar</strong> y espera unos segundos.</>} />
                        </div>

                        <Captura src="14-cuenta-conectada.jpg" alt="Pantalla de cuenta conectada"
                            pie="Conexión terminada. Meta revisa tu negocio y sólo te avisa si encuentra algún problema." />
                    </Paso>

                    <Paso
                        n="6"
                        donde="escritorio"
                        titulo="Agrega el método de pago"
                        ruta="Botón «Agregar método de pago», o después desde Facturación y pagos"
                    >
                        <p>
                            Lo que envías desde tu celular sigue siendo gratis. Lo que sale desde el CRM se
                            cobra a tarifa de Meta, y sin método de pago la cuenta no podrá enviar.
                        </p>

                        <Aviso tono="alto" titulo="Revisa la divisa antes de continuar">
                            <p>
                                Meta preselecciona una divisa que <strong>no siempre corresponde al país</strong>. En
                                nuestra prueba, con país Colombia, venía marcado <em>Dírham de EAU</em>.
                            </p>
                            <p>Ni la ubicación ni la divisa <strong>se pueden cambiar después</strong>.</p>
                        </Aviso>

                        <Captura src="15-metodo-pago.jpg" alt="Diálogo de información de pago con la divisa incorrecta"
                            pie={<>País <strong>Colombia</strong>, divisa <strong>Dírham de EAU</strong>. Cámbiala antes de pulsar Siguiente.</>} />
                    </Paso>

                    <Paso n="7" donde="escritorio" titulo="Ya está conectado" ruta="Integra CRM → Instancias">
                        <p>
                            Tu número aparece en la lista como conectado y desde ese momento cada mensaje que
                            te escriban entra al CRM. Puedes seguir respondiendo desde el celular: lo que
                            escribas ahí se ve aquí, y lo que envíes desde aquí le llega igual a tu cliente.
                        </p>
                        <p>
                            Tus contactos y tu historial se importan solos. Verás el avance en porcentaje en la
                            tarjeta de tu instancia. Mientras tanto, <strong>deja abierta la app de WhatsApp
                            Business</strong> en el celular, que agiliza el proceso.
                        </p>

                        <Aviso tono="bien" titulo="Cómo comprobar que todo quedó bien">
                            <p>
                                Escríbele a tu número desde otro teléfono. El mensaje debe aparecer <strong>a la
                                vez</strong> en la app del celular y aquí en el CRM.
                            </p>
                        </Aviso>
                    </Paso>
                </div>

                <div className="mt-2 rounded-xl border bg-card p-5">
                    <h2 className="text-base font-semibold text-foreground">Para mantenerlo funcionando</h2>
                    <ul className="mt-3 space-y-2.5 text-[13.5px] leading-relaxed text-muted-foreground">
                        <li>
                            <strong className="text-foreground">Abre la app del celular al menos cada 14 días.</strong>{' '}
                            Si no, Meta pausa la conexión por inactividad hasta que vuelvas a abrirla.
                        </li>
                        <li>
                            <strong className="text-foreground">Vuelve a vincular tus dispositivos.</strong>{' '}
                            WhatsApp Web y los demás quedaron desconectados durante el proceso.
                        </li>
                        <li>
                            <strong className="text-foreground">Un solo sistema a la vez.</strong>{' '}
                            Conectar el número a otra plataforma lo desconecta de aquí.
                        </li>
                        <li>
                            <strong className="text-foreground">Para desconectar:</strong>{' '}
                            en tu celular, Ajustes → Cuenta → Plataforma para empresas → tocar para desconectar.
                            Tu app de WhatsApp Business no se ve afectada.
                        </li>
                    </ul>
                </div>

                <div className="rounded-xl border bg-card p-5">
                    <h2 className="text-base font-semibold text-foreground">Si algo no sale bien</h2>
                    <dl className="mt-3 space-y-3 text-[13.5px] leading-relaxed">
                        {[
                            ['«Este número ya está registrado en una cuenta de WhatsApp»', 'Falta el paso 1, o tu número no cumple los requisitos. Revisa que la cuenta lleve más de una semana de uso.'],
                            ['Mi número no aparece en la lista', 'Es lo normal: esa lista sólo trae números que ya están en la API. Escríbelo a mano.'],
                            ['Salen números de otra cuenta mía', 'También es normal. La lista muestra lo que aún no está conectado aquí, no la cuenta en la que estás.'],
                            ['«Tu número no es elegible, se necesita más actividad»', 'La cuenta de WhatsApp Business es demasiado reciente. Úsala con normalidad y vuelve a intentar en unos días.'],
                            ['La ventana no se abre', 'Tu navegador está bloqueando las ventanas emergentes.'],
                        ].map(([sintoma, causa]) => (
                            <div key={sintoma}>
                                <dt className="font-medium text-foreground">{sintoma}</dt>
                                <dd className="text-muted-foreground">{causa}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            </div>
        </>
    );
}

GuiaCoexistencia.layout = page => <AppLayout breadcrumb={['Instancias', 'Guía de conexión']}>{page}</AppLayout>;
