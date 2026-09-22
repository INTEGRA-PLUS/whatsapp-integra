import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Aviso, Captura, Paso, useAvance } from '@/components/guia';
import {
    ArrowLeft, FileText, Gauge, RotateCcw, ShieldCheck, TriangleAlert,
} from 'lucide-react';

/**
 * Cómo subir el límite de mensajes de WhatsApp.
 *
 * ## Qué problema resuelve
 *
 * Meta limita cada **cuenta empresarial** —no cada número— a una cantidad de
 * clientes DISTINTOS a los que se puede escribir primero en una ventana móvil
 * de 24 horas. Toda cuenta nueva arranca en 250. Un ISP que factura 400
 * clientes el mismo día ve llegar los primeros 250 y rebotar el resto.
 *
 * ## Por qué hace falta escribirla
 *
 * Por el nombre del error. Meta lo llama **«Spam Rate limit hit»**, y quien lo
 * lee entiende que lo reportaron por spam: se asusta, revisa sus plantillas,
 * llama a soporte y en algún caso deja de enviar por miedo. No es spam, no es
 * un castigo y no es un fallo del CRM — es el cupo. Un ISP puede tener la
 * calificación de calidad en «Alta» y chocar contra el tope todos los meses.
 *
 * Esa aclaración va lo primero, antes que cualquier instrucción, porque es la
 * conclusión equivocada que saca todo el mundo.
 *
 * ## Por qué vive aquí y no en un PDF
 *
 * Por lo mismo que la guía de coexistencia: se necesita en el momento en que se
 * está mirando la pantalla de los envíos fallidos, no en un archivo que alguien
 * mandó por correo la semana pasada.
 */

const IMG = '/img/limites-whatsapp';
const TOTAL = 13;

/** Dónde ocurre cada paso, que aquí siempre es el panel de Meta. */
const EN_META = (
    <span className="inline-flex items-center gap-1 rounded bg-info/15 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-info">
        Panel de Meta
    </span>
);

export default function GuiaLimitesWhatsApp() {
    const { paso, reiniciar, completados, porcentaje, terminado } =
        useAvance('guia-limites-whatsapp:pasos-hechos', TOTAL);

    return (
        <>
            <Head title="Subir el límite de mensajes" />

            <div className="mx-auto flex max-w-3xl flex-col gap-5 p-6 pb-24">

                <Link
                    href="/instances"
                    className="inline-flex w-fit items-center gap-1.5 text-[13px] text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Volver a Instancias
                </Link>

                <header>
                    <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                        Subir el límite de mensajes de WhatsApp
                    </h1>
                    <p className="mt-1.5 text-[14.5px] leading-relaxed text-muted-foreground">
                        Si al facturar te salen mensajes en <strong className="font-semibold text-foreground">Fallido</strong> con
                        el aviso <span className="font-mono text-[13px]">Spam Rate limit hit</span>, esta guía te dice
                        por qué pasa y cómo subir el cupo de 250 a 2.000 clientes por día.
                    </p>
                </header>

                {/* ── Lo primero de todo: no es spam ───────────────────────── */}
                <Aviso tono="bien" titulo="No te reportaron por spam">
                    <p>
                        El error se llama «Spam Rate limit hit» y eso confunde a todo el mundo.
                        <strong> No es un castigo, no es una denuncia y no es un fallo del sistema.</strong> Es
                        el cupo: Meta te deja escribirle primero a un número máximo de clientes
                        distintos cada 24 horas, y lo pasaste.
                    </p>
                    <p>
                        Se puede tener la calificación de calidad en <strong>Alta</strong> y aun así chocar
                        contra el tope todos los meses. Son dos cosas distintas.
                    </p>
                </Aviso>

                {/* ── 1. El síntoma ────────────────────────────────────────── */}
                <section className="flex flex-col gap-4 rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <TriangleAlert className="size-4 shrink-0 text-warning" />
                        <h2 className="text-[15px] font-semibold text-foreground">1. Cómo se ve el problema</h2>
                    </div>

                    <p className="text-[14px] leading-relaxed text-foreground/90">
                        En el historial de envíos aparece una pared de <strong>Fallido</strong>. Fíjate en que
                        algunos sí salieron: no es que el sistema esté caído, es que los primeros
                        entraron y a partir de ahí Meta empezó a rechazar.
                    </p>

                    <Captura
                        src={`${IMG}/00-sintoma-mensajes-fallidos-en-integra.png`}
                        alt="Lista de envíos con varios en estado Fallido y el aviso Spam Rate limit hit"
                        estrecha
                        pie={<>Cada línea roja dice <strong>Spam Rate limit hit</strong>. Las verdes son las que alcanzaron a entrar antes del tope.</>}
                    />

                    <h3 className="mt-1 text-[14px] font-semibold text-foreground">Confirma que es el cupo y no tu reputación</h3>

                    <p className="text-[14px] leading-relaxed text-foreground/90">
                        Entra al <strong>Administrador de WhatsApp</strong> y abre, en el menú de la
                        izquierda, <strong>Herramientas de la cuenta</strong>.
                    </p>

                    <Captura
                        src={`${IMG}/02-administrador-whatsapp-numeros-de-telefono.png`}
                        alt="Menú lateral del Administrador de WhatsApp"
                        estrecha
                        pie={<>Ahí están las dos pantallas que necesitas: <strong>Límites de mensajes</strong> y <strong>Números de teléfono</strong>.</>}
                    />

                    <p className="text-[14px] leading-relaxed text-foreground/90">
                        En <strong>Números de teléfono</strong>, mira la columna «Calificación de calidad».
                    </p>

                    <Captura
                        src={`${IMG}/01-calificacion-de-calidad-alta.png`}
                        alt="Columna de calificación de calidad mostrando Alta y estado Conectado"
                        pie={<>Estado <strong>Conectado</strong> y calidad <strong>Alta</strong>: no hay ningún problema de reputación. Es sólo el cupo.</>}
                    />

                    <Aviso tono="ojo" titulo="Si la calidad dice Media o Baja, eso es otra cosa">
                        <p>
                            Entonces sí hay un tema de calidad —mensajes que el cliente no esperaba,
                            gente que bloquea el número— y subir el límite no lo arregla. Se trata
                            aparte: escríbenos y lo miramos contigo.
                        </p>
                    </Aviso>
                </section>

                {/* ── 2. Las dos vías ──────────────────────────────────────── */}
                <section className="flex flex-col gap-4 rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <Gauge className="size-4 shrink-0 text-info" />
                        <h2 className="text-[15px] font-semibold text-foreground">2. Los niveles, y las dos formas de subir</h2>
                    </div>

                    <p className="text-[14px] leading-relaxed text-foreground/90">
                        El cupo sube por escalones, y cuenta <strong>clientes distintos a los que tú
                        escribes primero</strong> en 24 horas seguidas. Responderle a alguien que te
                        escribió no consume cupo.
                    </p>

                    <div className="flex flex-wrap items-center gap-2 text-[13px] font-semibold tabular-nums">
                        {['250', '2.000', '10.000', '100.000', 'ilimitado'].map((nivel, i) => (
                            <span key={nivel} className="flex items-center gap-2">
                                {i > 0 && <span className="text-muted-foreground/50">→</span>}
                                <span className={`rounded-lg px-2.5 py-1 ${
                                    i === 0 ? 'bg-warning/15 text-warning' : 'bg-muted text-muted-foreground'
                                }`}>
                                    {nivel}
                                </span>
                            </span>
                        ))}
                    </div>
                    <p className="-mt-1 text-[12.5px] text-muted-foreground">
                        Toda cuenta nueva arranca en 250.
                    </p>

                    <Captura
                        src={`${IMG}/03-limites-de-mensajes-nivel-250.png`}
                        alt="Pantalla de Límites de mensajes con el nivel actual en 250"
                        pie={<>Aquí ves tu nivel actual y, abajo, las dos formas de subirlo. El límite es <strong>de toda la cuenta empresarial</strong>, no de cada número.</>}
                    />

                    <p className="text-[14px] leading-relaxed text-foreground/90">
                        Meta ofrece dos caminos, y para un ISP <strong>sólo uno sirve de verdad</strong>:
                    </p>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-xl border border-success/40 bg-success/5 p-3.5">
                            <p className="flex items-center gap-1.5 text-[13.5px] font-semibold text-success">
                                <ShieldCheck className="size-4" /> Verificar la empresa
                            </p>
                            <p className="mt-1.5 text-[13px] leading-relaxed text-muted-foreground">
                                Enviar los datos de tu empresa para que Meta confirme que existe. Es el
                                camino real, y el que explica esta guía.
                            </p>
                        </div>
                        <div className="rounded-xl border bg-muted/30 p-3.5">
                            <p className="text-[13.5px] font-semibold text-muted-foreground">
                                1.000 clientes únicos en siete días
                            </p>
                            <p className="mt-1.5 text-[13px] leading-relaxed text-muted-foreground">
                                Iniciar conversaciones de calidad con 1.000 clientes distintos en siete
                                días seguidos.
                            </p>
                        </div>
                    </div>

                    <Aviso tono="info" titulo="Por qué el segundo camino casi nunca le sirve a un ISP">
                        <p>
                            Porque se factura en dos o tres días fijos del mes, así que en ninguna
                            ventana de siete días se llega a 1.000 clientes distintos. En la captura de
                            arriba se ve el caso real: <strong>255 conversaciones en los últimos siete
                            días</strong> de las 1.000 que pide Meta. Por muchos meses que pasen, ese
                            número no va a subir solo.
                        </p>
                    </Aviso>

                    <Aviso tono="bien" titulo="Ya no hace falta aprobar el «nombre para mostrar»">
                        <p>
                            Mucha gente sigue creyendo que primero hay que conseguir la aprobación del
                            nombre. Meta lo quitó como requisito: se puede verificar la empresa sin eso.
                        </p>
                    </Aviso>
                </section>

                {/* ── El desvío que no lleva a ninguna parte ───────────────── */}
                <Aviso tono="alto" titulo="Ojo: hay alertas en el Centro de seguridad que NO suben el límite">
                    <p>
                        Cuando entres al Centro de seguridad vas a ver avisos de «Action needed» que
                        parecen urgentes: <strong>dominios de confianza</strong>, <strong>passkeys</strong>,
                        <strong> aprobación por pares</strong>. Son de seguridad de las cuentas
                        <strong> publicitarias</strong> y no tienen ninguna relación con WhatsApp.
                        Llenarlas no sube el cupo.
                    </p>
                    <p>
                        Es el desvío en el que cae casi todo el mundo. Lo único que te interesa de esa
                        pantalla es la tarjeta <strong>«Verificación de la empresa»</strong>.
                    </p>
                </Aviso>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Captura
                        src={`${IMG}/AVISO-centro-seguridad-alertas-de-anuncios.png`}
                        alt="Alertas del Centro de seguridad sobre dominios y passkeys"
                        pie={<>Estas alertas son de <strong>anuncios</strong>. Ignóralas para lo que vienes a hacer.</>}
                    />
                    <Captura
                        src={`${IMG}/AVISO-domain-security-no-aplica.png`}
                        alt="Modal de Domain Security"
                        pie={<>«Domain Security» tampoco: es para quien publica anuncios con dominios propios.</>}
                    />
                </div>

                {/* ── 3. El paso a paso ────────────────────────────────────── */}
                <section className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-[15px] font-semibold text-foreground">3. Verificar la empresa, paso a paso</h2>
                            <p className="mt-0.5 text-[13px] text-muted-foreground">
                                Trece pasos. Se marca cada uno al terminarlo y la guía recuerda por dónde ibas.
                            </p>
                        </div>

                        {completados > 0 && (
                            <button
                                type="button"
                                onClick={reiniciar}
                                className="inline-flex items-center gap-1.5 text-[12.5px] text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <RotateCcw className="size-3.5" /> Empezar de nuevo
                            </button>
                        )}
                    </div>

                    {completados > 0 && (
                        <div className="flex items-center gap-3">
                            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary transition-all"
                                    style={{ width: `${porcentaje}%` }}
                                />
                            </div>
                            <span className="text-[12px] font-semibold tabular-nums text-muted-foreground">
                                {completados} de {TOTAL}
                            </span>
                        </div>
                    )}

                    <Paso
                        {...paso(1)}
                        titulo="Abre Límites de mensajes"
                        etiqueta={EN_META}
                        ruta="Administrador de WhatsApp › Herramientas de la cuenta › Límites de mensajes"
                    >
                        <p>
                            Abajo, en «Aumenta tu límite de mensajes», pulsa <strong>Empezar</strong> dentro
                            de la tarjeta <strong>Verifica tu empresa</strong>.
                        </p>
                        <Captura
                            src={`${IMG}/03-limites-de-mensajes-nivel-250.png`}
                            alt="Botón Empezar bajo Verifica tu empresa"
                            pie={<>El botón <strong>Empezar</strong> está bajo «Verifica tu empresa», no en la opción de abajo.</>}
                        />
                    </Paso>

                    <Paso {...paso(2)} titulo="Inicia la verificación" etiqueta={EN_META}>
                        <p>Se abre un cuadro que resume lo que vas a conseguir. Pulsa <strong>Iniciar verificación</strong>.</p>
                        <Captura
                            src={`${IMG}/04-modal-aumenta-los-limites.png`}
                            alt="Modal Aumenta los límites con el botón Iniciar verificación"
                            pie="Te lleva al Centro de seguridad del Administrador comercial."
                        />
                    </Paso>

                    <Paso {...paso(3)} titulo="Busca la tarjeta «Verificación de la empresa»" etiqueta={EN_META}>
                        <p>Antes de continuar, comprueba dos cosas en esa tarjeta:</p>
                        <ul className="ml-4 list-disc space-y-1">
                            <li>El caso de uso dice <strong>«Cómo crear una cuenta de WhatsApp Business»</strong>.</li>
                            <li>Aparece <strong>«Cumple los requisitos para su verificación»</strong>.</li>
                        </ul>
                        <p>Con eso, pulsa <strong>Iniciar verificación</strong>.</p>
                        <Captura
                            src={`${IMG}/05-centro-seguridad-verificacion-de-la-empresa.png`}
                            alt="Tarjeta de verificación de la empresa en el Centro de seguridad"
                            pie={<>Si el desplegable muestra otro caso de uso, cámbialo antes de seguir.</>}
                        />
                        <Aviso tono="alto" titulo="Las otras alertas de esta pantalla no son tuyas">
                            <p>Dominios, passkeys y aprobación por pares son de anuncios. No las toques.</p>
                        </Aviso>
                    </Paso>

                    <Paso {...paso(4)} titulo="Pantalla de bienvenida" etiqueta={EN_META}>
                        <p>Te dice qué te va a pedir: nombre, dirección, teléfono y sitio web. Pulsa <strong>Empezar</strong>.</p>
                        <Captura
                            src={`${IMG}/06-bienvenida-verificacion.png`}
                            alt="Pantalla de bienvenida de la verificación"
                            estrecha
                            pie="Ten el RUT y el certificado de Cámara de Comercio a mano antes de seguir."
                        />
                    </Paso>

                    <Paso {...paso(5)} titulo="Elige el país" etiqueta={EN_META}>
                        <p>Colombia, o el país donde esté registrada la empresa.</p>
                        <Captura
                            src={`${IMG}/07-selecciona-pais.png`}
                            alt="Selector de país"
                            estrecha
                            pie="Tiene que coincidir con el país del registro mercantil."
                        />
                    </Paso>

                    <Paso {...paso(6)} titulo="Tipo de empresa" etiqueta={EN_META}>
                        <p>
                            Para una <strong>S.A.S. colombiana</strong> —y también para una Ltda. o una
                            S.R.L.— la opción es <strong>Empresa privada</strong>.
                        </p>
                        <Captura
                            src={`${IMG}/08-tipo-de-empresa.png`}
                            alt="Las cinco opciones de tipo de empresa"
                            estrecha
                            pie={<><strong>Empresa privada</strong> es la que corresponde. Las otras cuatro son para otra cosa.</>}
                        />
                        <dl className="space-y-1.5 rounded-lg bg-muted/40 px-3.5 py-3 text-[13px]">
                            {[
                                ['Compañía', 'sólo para empresas que cotizan en bolsa'],
                                ['Sociedad unipersonal', 'persona natural con nombre comercial'],
                                ['Asociación', 'sociedad de personas con responsabilidades compartidas'],
                                ['Institución', 'entidad pública, educativa, hospital o sin ánimo de lucro'],
                            ].map(([que, cuando]) => (
                                <div key={que} className="flex flex-wrap gap-x-2">
                                    <dt className="font-medium text-foreground">{que}:</dt>
                                    <dd className="text-muted-foreground">{cuando}</dd>
                                </div>
                            ))}
                        </dl>
                    </Paso>

                    <Paso {...paso(7)} titulo="El nombre de la empresa" etiqueta={EN_META}>
                        <Aviso tono="alto" titulo="Este es el paso que más rechazos causa">
                            <p>
                                El campo viene <strong>prellenado con el nombre de tu portafolio</strong>, que
                                suele ser la sigla comercial. <strong>Bórralo.</strong>
                            </p>
                        </Aviso>

                        <p>
                            En <strong>«Nombre de la empresa»</strong> va la <strong>razón social completa</strong>,
                            exactamente como aparece en el RUT y en el certificado de Cámara de Comercio.
                            En <strong>«Nombre alternativo»</strong> va la sigla o el nombre comercial.
                        </p>

                        <div className="rounded-lg border bg-muted/40 px-3.5 py-3 font-mono text-[12.5px] leading-relaxed">
                            <p><span className="text-muted-foreground">Nombre de la empresa:</span> REDES Y COMUNICACIONES DEL LLANO S.A.S</p>
                            <p><span className="text-muted-foreground">Nombre alternativo:</span> REDCOM SAS</p>
                        </div>
                        <p className="text-[12.5px] text-muted-foreground">Ejemplo con datos ficticios.</p>

                        <Captura
                            src={`${IMG}/09-nombre-legal-y-alternativo.png`}
                            alt="Campos de nombre de la empresa y nombre alternativo"
                            estrecha
                            pie={<>El primer campo llega relleno con la sigla. Bórralo y pon la razón social del RUT.</>}
                        />

                        <Aviso tono="ojo" titulo="Cópialo del RUT, no lo escribas de memoria">
                            <p>
                                Meta compara <strong>carácter por carácter</strong>. Ten el RUT abierto y pega
                                el nombre desde ahí. Ojo con el punto final de «S.A.S»: que quede igual que
                                en el documento.
                            </p>
                        </Aviso>
                    </Paso>

                    <Paso {...paso(8)} titulo="La dirección" etiqueta={EN_META}>
                        <p>
                            Sale del RUT, pero hay que <strong>desabreviarla</strong>: el RUT la escribe en
                            formato DIAN (<span className="font-mono text-[12.5px]">CL 27 25 33 BRR PORVENIR</span>)
                            y en Meta va legible.
                        </p>

                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[26rem] text-[13px]">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="py-2 pr-4 font-medium">Campo de Meta</th>
                                        <th className="py-2 font-medium">De dónde sale</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {[
                                        ['Dirección postal', 'Calle o carrera y número, escrito completo'],
                                        ['Dirección postal 2 / Localidad', 'El barrio'],
                                        ['Ciudad', 'Campo 40 del RUT'],
                                        ['Estado, provincia o región', 'El departamento (campo 39)'],
                                        ['Código postal', 'No está en el RUT: búscalo en el localizador de 4-72'],
                                    ].map(([campo, donde]) => (
                                        <tr key={campo} className="border-b last:border-0">
                                            <td className="py-2 pr-4 font-medium text-foreground">{campo}</td>
                                            <td className="py-2 text-muted-foreground">{donde}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <Captura
                            src={`${IMG}/10-direccion.png`}
                            alt="Formulario de dirección"
                            estrecha
                            pie="La dirección también se compara con la registrada: que coincida."
                        />
                    </Paso>

                    <Paso {...paso(9)} titulo="Teléfono y sitio web" etiqueta={EN_META}>
                        <p>
                            El teléfono tiene que poder <strong>recibir un código de confirmación</strong>, así
                            que pon uno al que tengas acceso ahora mismo. Lo ideal es que sea el mismo
                            que figura en el RUT.
                        </p>
                        <Captura
                            src={`${IMG}/11-telefono-y-sitio-web.png`}
                            alt="Campos de teléfono y sitio web"
                            estrecha
                            pie="Más adelante Meta llamará o escribirá a este número."
                        />
                    </Paso>

                    <Paso {...paso(10)} titulo="Confirma que no eres un robot" etiqueta={EN_META}>
                        <p>Marca la casilla y continúa.</p>
                        <Captura
                            src={`${IMG}/12-recaptcha.png`}
                            alt="Casilla de reCAPTCHA"
                            estrecha
                            pie="Un paso de trámite, sin más."
                        />
                    </Paso>

                    <Paso {...paso(11)} titulo="Selecciona tu empresa en el registro público" etiqueta={EN_META}>
                        <p>
                            Meta busca tu empresa en el registro público —en Colombia, el RUES— y te
                            muestra las coincidencias. Elige la que tenga los datos correctos y esté
                            vigente.
                        </p>
                        <Captura
                            src={`${IMG}/13-selecciona-tu-empresa-registro-publico.png`}
                            alt="Resultados del registro público con dos coincidencias"
                            estrecha
                            pie={<>Fíjate en que Meta ya enmascara los datos sensibles. Si hay varias, elige la de la dirección vigente.</>}
                        />
                        <Aviso tono="bien" titulo="Que aparezca aquí es la mejor noticia de todo el proceso">
                            <p>
                                Significa que la verificación es automática y <strong>no tienes que subir
                                ningún documento</strong>. Si no aparece ninguna, elige «Mi empresa no figura
                                en la lista» y sigue por la ruta de documentos.
                            </p>
                        </Aviso>
                    </Paso>

                    <Paso {...paso(12)} titulo="Revisa los datos y confirma tu conexión" etiqueta={EN_META}>
                        <p>
                            Última mirada antes de enviar. Después elige cómo quieres recibir el código:
                            <strong> llamada, SMS o WhatsApp</strong>, e ingrésalo.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Captura
                                src={`${IMG}/14-revisa-los-detalles.png`}
                                alt="Resumen de los datos antes de enviar"
                                pie="Si algo no cuadra con el RUT, vuelve atrás ahora."
                            />
                            <Captura
                                src={`${IMG}/15-metodo-de-confirmacion.png`}
                                alt="Las tres formas de recibir el código"
                                pie="El código llega al teléfono del paso 9."
                            />
                        </div>
                    </Paso>

                    <Paso {...paso(13)} titulo="Envía y espera" etiqueta={EN_META}>
                        <p>Ya está. El estado queda en <strong>«En revisión»</strong>.</p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Captura
                                src={`${IMG}/16-informacion-enviada.png`}
                                alt="Confirmación de información enviada"
                                pie="Meta confirma que recibió los datos."
                            />
                            <Captura
                                src={`${IMG}/17-estado-en-revision.png`}
                                alt="Estado En revisión con el plazo estimado"
                                pie={<>Aproximadamente <strong>dos días hábiles</strong>.</>}
                            />
                        </div>
                    </Paso>
                </section>

                {/* ── 4. Cuánto tarda ──────────────────────────────────────── */}
                <section className="flex flex-col gap-3 rounded-xl border bg-card p-5">
                    <h2 className="text-[15px] font-semibold text-foreground">4. Cuánto tarda</h2>
                    <ul className="space-y-1.5 text-[14px] leading-relaxed text-foreground/90">
                        <li>· Meta revisa en <strong>unos dos días hábiles</strong>.</li>
                        <li>· Tras la aprobación, el límite puede tardar <strong>hasta 24 horas más</strong> en subir.</li>
                        <li>· El nuevo cupo queda en <strong>2.000 conversaciones al día</strong>.</li>
                    </ul>
                    <p className="text-[14px] leading-relaxed text-foreground/90">
                        Para comprobarlo, vuelve a <strong>Límites de mensajes</strong>: donde antes decía
                        250 debe decir 2.000.
                    </p>
                </section>

                {/* ── 5. Si la rechazan ───────────────────────────────────── */}
                <section className="flex flex-col gap-3 rounded-xl border bg-card p-5">
                    <h2 className="text-[15px] font-semibold text-foreground">5. Si te la rechazan</h2>
                    <p className="text-[14px] leading-relaxed text-foreground/90">
                        Meta <strong>no dice qué campo falló</strong>. Hay que revisarlos todos contra el
                        documento y volver a enviar. Por orden de frecuencia:
                    </p>
                    <ol className="ml-4 list-decimal space-y-1.5 text-[14px] leading-relaxed text-foreground/90">
                        <li>El nombre legal no coincide <strong>carácter por carácter</strong> con el documento.</li>
                        <li>La dirección no coincide con la registrada.</li>
                        <li>Documentos vencidos: el certificado de Cámara de Comercio debe ser reciente.</li>
                        <li>El teléfono no recibió el código.</li>
                    </ol>
                </section>

                {/* ── 6. Mientras tanto ───────────────────────────────────── */}
                <section className="flex flex-col gap-3 rounded-xl border bg-card p-5">
                    <h2 className="text-[15px] font-semibold text-foreground">6. Qué hacer mientras esperas</h2>

                    <Aviso tono="alto" titulo="No reenvíes a mano los que fallaron">
                        <p>Cada rechazo cuenta contra ti. Reintentar sobre el tope lo empeora.</p>
                    </Aviso>

                    <ul className="space-y-2 text-[14px] leading-relaxed text-foreground/90">
                        <li>
                            · Si facturas más clientes en un día de los que permite tu cupo, ponle un
                            <strong> tope diario de envíos</strong>: el sistema manda hasta el tope y deja el
                            resto para el día siguiente, en vez de que reboten.
                        </li>
                        <li>
                            · O reparte los grupos de corte en más días del mes. Con el cupo en 250,
                            facturar 400 clientes el mismo día no cabe de ninguna manera.
                        </li>
                    </ul>
                </section>

                {/* ── 7. Documentos ───────────────────────────────────────── */}
                <section className="flex flex-col gap-3 rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <FileText className="size-4 shrink-0 text-muted-foreground" />
                        <h2 className="text-[15px] font-semibold text-foreground">Ten esto a mano (Colombia)</h2>
                    </div>
                    <ul className="space-y-1.5 text-[14px] leading-relaxed text-foreground/90">
                        <li>· <strong>RUT</strong> de la DIAN — de ahí salen razón social, NIT, dirección y teléfono.</li>
                        <li>· <strong>Certificado de existencia y representación legal</strong> de Cámara de Comercio, reciente.</li>
                        <li>· Un teléfono o correo corporativo que pueda recibir el código.</li>
                    </ul>
                    <p className="text-[13px] text-muted-foreground">
                        Los documentos sólo se suben si Meta <strong>no encuentra</strong> tu empresa en el
                        registro público. Si la encuentra, no hace falta ninguno.
                    </p>
                </section>

                {terminado && (
                    <Aviso tono="bien" titulo="Enviado. Ahora toca esperar.">
                        <p>
                            En unos dos días hábiles Meta responde, y hasta 24 horas después el límite
                            sube. Si pasada una semana sigue en «En revisión», escríbenos.
                        </p>
                    </Aviso>
                )}
            </div>
        </>
    );
}

GuiaLimitesWhatsApp.layout = page => (
    <AppLayout breadcrumb={['Instancias', 'Subir el límite de mensajes']}>{page}</AppLayout>
);
