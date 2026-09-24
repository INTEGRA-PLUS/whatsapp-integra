import { useState } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Aviso, Captura as CapturaBase, Paso as PasoBase, useAvance } from '@/components/guia';
import CabeceraModulo from '@/components/cabecera-modulo';
import { ChevronDown, Monitor, RotateCcw, Smartphone, Unplug } from 'lucide-react';

/**
 * La guía de conexión por coexistencia, dentro del producto.
 *
 * Está aquí y no en un PDF porque el cliente la necesita en el momento en que
 * está mirando el botón, no en un archivo que alguien le mandó por correo la
 * semana pasada.
 *
 * Es un recorrido, no un documento. Ocho pasos con diecinueve capturas en una
 * sola página abrumaban: el cliente no sabía por dónde iba y abandonaba a la
 * mitad. Ahora se abre un paso a la vez, se marca al terminarlo y el avance se
 * guarda en el navegador — quien cierra la pestaña con el celular en la mano
 * vuelve exactamente donde estaba.
 *
 * La estructura sigue lo que de verdad complica este proceso: la mitad de los
 * pasos ocurren en el navegador y la otra mitad en el celular del cliente. Cada
 * paso lleva su etiqueta para que sepa dónde tiene que estar mirando.
 */

const IMG = '/img/coexistencia';
const MEMORIA = 'guia-coexistencia:pasos-hechos';

function Etiqueta({ donde }) {
    const esCelular = donde === 'celular';
    const Icono = esCelular ? Smartphone : Monitor;

    return (
        <span className={`inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ${
            esCelular
                ? 'bg-success/15 text-success'
                : 'bg-info/15 text-info'
        }`}>
            <Icono className="size-2.5" />
            {esCelular ? 'Celular' : 'Escritorio'}
        </span>
    );
}

/** Envoltorios para no repetir en cada llamada lo que es propio de esta guía. */
function Captura({ src, celular = false, ...resto }) {
    return <CapturaBase src={`${IMG}/${src}`} estrecha={celular} {...resto} />;
}

function Paso({ donde, ...resto }) {
    return <PasoBase etiqueta={<Etiqueta donde={donde} />} {...resto} />;
}

export default function GuiaCoexistencia() {
    const TOTAL = 8;
    const { paso: props, reiniciar, completados, porcentaje, terminado } = useAvance(MEMORIA, TOTAL);
    const [verDesconectar, setVerDesconectar] = useState(false);

    return (
        <>
            <Head title="Conectar tu WhatsApp Business" />
            <div className="mx-auto flex max-w-3xl flex-col gap-5 p-6 pb-24">

                <CabeceraModulo
                    icono={Smartphone}
                    volver="/instances"
                    titulo="Conectar el número que ya usas en WhatsApp Business"
                    descripcion="Sin perder tus chats y sin dejar de responder desde el celular. Toma unos 15 minutos y necesitas el teléfono a la mano."
                />

                {/* Avance del recorrido */}
                <div className="sticky top-2 z-10 rounded-xl border bg-card/95 px-4 py-3 shadow-xs backdrop-blur">
                    <div className="flex items-center justify-between gap-4">
                        <p className="text-[13px] font-semibold text-foreground">
                            {terminado ? '¡Listo! Completaste los 8 pasos' : `Paso ${Math.min(completados + 1, TOTAL)} de ${TOTAL}`}
                        </p>
                        <div className="flex items-center gap-3">
                            <span className="font-mono text-[13px] tabular-nums text-muted-foreground">{porcentaje}%</span>
                            {completados > 0 && (
                                <button
                                    type="button"
                                    onClick={reiniciar}
                                    className="inline-flex items-center gap-1 text-[12px] font-medium text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    <RotateCcw className="size-3" /> Reiniciar
                                </button>
                            )}
                        </div>
                    </div>
                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            className={`h-full rounded-full transition-[width] duration-500 ${terminado ? 'bg-success' : 'bg-primary'}`}
                            style={{ width: `${porcentaje}%` }}
                        />
                    </div>
                </div>

                <Aviso tono="info" titulo="Antes de empezar, comprueba cuatro cosas">
                    <p>El número usa la app de <strong>WhatsApp Business</strong>, no la de WhatsApp normal.</p>
                    <p>Lleva <strong>más de una semana</strong> en uso: Meta pide actividad real antes de aceptarlo.</p>
                    <p>Tienes una <strong>cuenta de Facebook</strong>: es con la que se entra a Meta y se crea
                    el portafolio. No hace falta página ni publicar nada.</p>
                    <p>Tienes el <strong>celular a la mano</strong>, con cámara y batería.</p>
                </Aviso>

                <Aviso tono="ojo" titulo="Qué cambia en tu celular">
                    <p>Sigues respondiendo desde la app y no pierdes ningún chat. Pero se <strong>desconectan
                    los dispositivos vinculados</strong> —WhatsApp Web incluido— durante el proceso, las
                    <strong> listas de difusión</strong> quedan de solo lectura, y se desactivan los
                    <strong> mensajes temporales</strong> y los de <strong>ver una vez</strong> en los chats
                    individuales. Los <strong>grupos</strong> siguen funcionando en tu celular pero no entran aquí.</p>
                </Aviso>

                <div className="mt-1 flex flex-col gap-2.5">
                    <Paso
                        {...props(1)}
                        donde="escritorio"
                        titulo="Crea tu portafolio comercial de Meta"
                        ruta="business.facebook.com → selector de arriba a la izquierda → Crear un portafolio comercial"
                    >
                        <p>
                            El portafolio comercial es tu empresa dentro de Meta: agrupa tu WhatsApp, tu
                            página y quién puede administrarlos. Es gratis y se crea con tu cuenta de
                            Facebook de siempre, sin nada más.
                        </p>
                        <p>
                            <strong>Si ya tienes uno, salta al paso 2.</strong> Lo reconoces porque al entrar a
                            Meta Business Suite ya aparece el nombre de tu negocio arriba a la izquierda.
                        </p>

                        <ol className="ml-1 flex flex-col gap-2.5">
                            {[
                                ['Entra a Meta Business Suite', 'Con tu cuenta de Facebook normal.'],
                                ['Abre el selector de arriba a la izquierda', 'Y elige «Crear un portafolio comercial».'],
                                ['Llena los tres datos', 'Nombre de tu negocio, tu nombre y apellido, y un correo del negocio.'],
                                ['Confirma el correo que te llega', 'Sin abrir ese enlace, el portafolio queda a medias.'],
                            ].map(([titulo, detalle], i) => (
                                <li key={titulo} className="flex gap-3">
                                    <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-bold tabular-nums text-muted-foreground">
                                        {i + 1}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-[13.5px] font-medium text-foreground">{titulo}</span>
                                        <span className="block text-[12.5px] leading-snug text-muted-foreground">{detalle}</span>
                                    </span>
                                </li>
                            ))}
                        </ol>

                        <Aviso tono="ojo" titulo="Completa la información del negocio">
                            <p>
                                Nombre legal, dirección, sitio web y teléfono. Meta puede <strong>restringir
                                cuentas</strong> con esos datos incompletos, y suele hacerlo semanas después,
                                cuando ya estás usando el número a diario.
                            </p>
                        </Aviso>

                        <a
                            href="https://business.facebook.com/"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-primary underline underline-offset-2"
                        >
                            Abrir Meta Business Suite en otra pestaña
                        </a>
                    </Paso>

                    <Paso
                        {...props(2)}
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

                        <Captura src="01-menu-agregar.jpg" alt="Menú Agregar con las tres opciones"
                            pie={<>Las tres opciones del botón <strong>Agregar</strong>. La de abajo es la que sirve.</>} />

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Captura src="02-cuenta-vinculada.jpg" alt="Confirmación de cuenta vinculada"
                                pie={<>Meta confirma la vinculación. Pulsa <strong>Listo</strong>.</>} />
                            <Captura src="03-activo-portafolio.jpg" alt="La cuenta en el portafolio"
                                pie={<>Queda con el subtítulo <strong>App de WhatsApp Business</strong>.</>} />
                        </div>

                        <Captura src="04-numero-sin-conexion.jpg" alt="Número con estado Sin conexión"
                            pie={<>Aparecerá como <strong>Sin conexión</strong>. Es lo normal: todavía no está conectado aquí.</>} />
                    </Paso>

                    <Paso
                        {...props(3)}
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
                        {...props(4)}
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

                        <Captura src="05-qr-importar.jpg" alt="Pantalla de importar contactos e historial con código QR"
                            pie={<>Si ves esta pantalla, vas bien. A partir de aquí sigue <strong>en tu celular</strong>. No cierres esta ventana.</>} />
                    </Paso>

                    <Paso
                        {...props(5)}
                        donde="celular"
                        titulo="Autoriza desde tu teléfono"
                        ruta="Cinco pantallas seguidas. Ten la ventana del computador a la vista: hay que escanear el código."
                    >
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Captura celular src="06-mensaje-facebook.jpg" alt="Mensaje de Facebook Business"
                                pie={<><strong>1.</strong> Te llega un mensaje de <strong>Facebook Business</strong>. Toca <strong>Connect</strong>.</>} />
                            <Captura celular src="07-conectate-plataforma.jpg" alt="Conéctate a la plataforma"
                                pie={<><strong>2.</strong> Toca <strong>Conéctate a la plataforma para empresas</strong>.</>} />
                            <Captura celular src="08-comparte-historial.jpg" alt="Compartir el historial de chats"
                                pie={<><strong>3.</strong> Elige <strong>Compartir todos los chats</strong> y confirma.</>} />
                        </div>

                        <Aviso tono="ojo" titulo="Esta decisión no se puede cambiar después">
                            <p>
                                Si eliges <em>No compartir chats</em>, no se importará nada y tendrás que empezar
                                conversaciones nuevas con tus clientes actuales.
                            </p>
                        </Aviso>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <Captura celular src="09-escanea-qr.jpg" alt="Escanear el código QR"
                                pie={<><strong>4.</strong> Toca <strong>OK</strong> y apunta la cámara al código de tu computador.</>} />
                            <Captura celular src="10-plataforma-conectada.jpg" alt="Plataforma conectada"
                                pie={<><strong>5.</strong> Aparece <strong>Plataforma conectada</strong>. Aún dice que falta configurar: es normal.</>} />
                            <Captura celular src="11-te-conectaste.jpg" alt="Confirmación de conexión"
                                pie="Y la confirmación de que tu historial se está compartiendo." />
                        </div>

                        <Aviso tono="info" titulo="Si la cámara no lee el código">
                            <p>Abajo hay un enlace <strong>Ingresa el código de acceso</strong>. La ventana del computador te muestra ese código como alternativa.</p>
                        </Aviso>
                    </Paso>

                    <Paso
                        {...props(6)}
                        donde="escritorio"
                        titulo="Confirma tu cuenta"
                        ruta="Vuelve a la ventana del computador, que habrá avanzado sola"
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Captura src="12-confirma-cuenta.jpg" alt="Confirmar o editar la cuenta"
                                pie={<>Revisa el <strong>nombre</strong> —es el que verán tus clientes— y la <strong>zona horaria</strong>.</>} />
                            <Captura src="13-revisa-permisos.jpg" alt="Resumen de permisos"
                                pie={<>El resumen de lo que compartes. Pulsa <strong>Confirmar</strong> y espera unos segundos.</>} />
                        </div>

                        <Captura src="14-cuenta-conectada.jpg" alt="Cuenta conectada"
                            pie="Conexión terminada. Meta revisa tu negocio y sólo te avisa si encuentra algún problema." />
                    </Paso>

                    <Paso
                        {...props(7)}
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

                        <Captura src="15-metodo-pago.jpg" alt="Información de pago con la divisa incorrecta"
                            pie={<>País <strong>Colombia</strong>, divisa <strong>Dírham de EAU</strong>. Cámbiala antes de pulsar Siguiente.</>} />
                    </Paso>

                    <Paso {...props(8)} donde="escritorio" titulo="Ya está conectado" ruta="Integra CRM → Instancias">
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

                {terminado && (
                    <Aviso tono="bien" titulo="Recorrido completado">
                        <p>
                            Tu número ya está trabajando en los dos lados. Si algo no cuadra, revisa
                            «Si algo no sale bien» más abajo.
                        </p>
                    </Aviso>
                )}

                {/* Mantenimiento */}
                <div className="mt-3 rounded-xl border bg-card p-5">
                    <h2 className="text-base font-semibold text-foreground">Para mantenerlo funcionando</h2>
                    <ul className="mt-3 space-y-2.5 text-[13.5px] leading-relaxed text-muted-foreground">
                        <li><strong className="text-foreground">Abre la app del celular al menos cada 14 días.</strong>{' '}
                            Si no, Meta pausa la conexión por inactividad hasta que vuelvas a abrirla.</li>
                        <li><strong className="text-foreground">Vuelve a vincular tus dispositivos.</strong>{' '}
                            WhatsApp Web y los demás quedaron desconectados durante el proceso.</li>
                        <li><strong className="text-foreground">Un solo sistema a la vez.</strong>{' '}
                            Conectar el número a otra plataforma lo desconecta de aquí.</li>
                        <li><strong className="text-foreground">Sobre el nombre verificado.</strong>{' '}
                            En números conectados así, Meta no revisa el nombre para mostrar de forma
                            automática, y la insignia azul de cuenta oficial no está disponible. Si te
                            interesa que tu nombre aparezca verificado, la vía es solicitar Meta Verified
                            para empresas.</li>
                    </ul>
                </div>

                {/* Desconectar: plegado, porque es la excepción y no el camino */}
                <div className="overflow-hidden rounded-xl border bg-card">
                    <button
                        type="button"
                        onClick={() => setVerDesconectar(v => !v)}
                        aria-expanded={verDesconectar}
                        className="flex w-full items-center gap-3 px-5 py-4 text-left transition-colors hover:bg-black/[.03] dark:hover:bg-white/[.04]"
                    >
                        <Unplug className="size-4 shrink-0 text-muted-foreground" />
                        <span className="flex-1">
                            <span className="block text-[14.5px] font-semibold text-foreground">
                                ¿Necesitas desconectar tu número?
                            </span>
                            <span className="block text-[12.5px] text-muted-foreground">
                                Se hace desde tu celular, no desde aquí. Tu app de WhatsApp Business no se ve afectada.
                            </span>
                        </span>
                        <ChevronDown className={`size-4 shrink-0 text-muted-foreground transition-transform ${verDesconectar ? 'rotate-180' : ''}`} />
                    </button>

                    {verDesconectar && (
                        <div className="space-y-4 border-t px-5 pb-6 pt-4">
                            <p className="rounded-lg bg-muted/50 px-3 py-2 font-mono text-[11.5px] leading-relaxed text-muted-foreground">
                                WhatsApp Business → Ajustes → Cuenta → Plataforma para empresas → tocar para desconectar
                            </p>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Captura celular src="16-ajustes-app.jpg" alt="Menú de Ajustes de WhatsApp Business"
                                    pie={<><strong>1.</strong> En tu app, abre <strong>Ajustes</strong> y entra en <strong>Cuenta</strong>.</>} />
                                <Captura celular src="17-cuenta-plataforma.jpg" alt="Opción Plataforma para empresas dentro de Cuenta"
                                    pie={<><strong>2.</strong> Toca <strong>Plataforma para empresas</strong>.</>} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Captura celular src="18-tocar-desconectar.jpg" alt="Pantalla de plataforma conectada"
                                    pie={<><strong>3.</strong> Verás la plataforma conectada. <strong>Toca la tarjeta</strong> para desconectar.</>} />
                                <Captura celular src="19-confirmar-desconexion.jpg" alt="Confirmación de desconexión"
                                    pie={<><strong>4.</strong> Lee lo que implica y confirma con <strong>Siguiente</strong>.</>} />
                            </div>

                            <Aviso tono="ojo" titulo="Qué pasa cuando desconectas">
                                <p>
                                    Tu <strong>app de WhatsApp Business sigue funcionando igual</strong> y no pierdes
                                    ningún chat del celular.
                                </p>
                                <p>
                                    Lo que se detiene es el CRM: dejarás de recibir y enviar mensajes desde aquí, y
                                    los chats y contactos que se compartieron pueden eliminarse.
                                </p>
                                <p>
                                    Para volver, hay que <strong>repetir el proceso completo</strong> desde el paso 1, y
                                    la importación del historial vuelve a ser de un solo intento.
                                </p>
                            </Aviso>
                        </div>
                    )}
                </div>

                {/* Problemas */}
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
