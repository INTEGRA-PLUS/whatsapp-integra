import { FileType } from 'lucide-react';

/**
 * Las guías, como datos.
 *
 * Viven en el código y no en la base de datos a propósito: describen pantallas
 * del propio CRM, así que cambian cuando cambia la pantalla, en el mismo commit.
 * Una guía guardada aparte se queda describiendo un botón que ya no existe y
 * nadie se entera.
 *
 * Toda guía es un paso a paso. Cada paso dice **dónde** se hace, **cuánto**
 * tarda y qué suele salir mal, porque las preguntas que llegan por WhatsApp son
 * siempre esas tres: «¿dónde lo hago?», «¿cuánto se demora?» y «¿por qué no me
 * deja?».
 *
 * `imagenes` son capturas de la pantalla real, hechas con una empresa de
 * demostración (nombre, número y WABA ficticios) y con lo que hay que tocar
 * marcado en naranja. Viven en `public/img/guias/<slug>/` y no en
 * `public/guias/`: una carpeta real con el mismo camino que la URL de la guía
 * hace que nginx la sirva como directorio en vez de pasarle la página a Laravel.
 * Si cambia el formulario, hay que rehacerlas.
 *
 * `compartir` es el texto corto que se le pega a quien pregunta. Nació con la
 * pregunta que más se repite: «Me indica el procedimiento para crear una
 * plantilla que necesito» (28-sep-2026).
 */
export const GUIAS = [
    {
        slug: 'crear-plantilla',
        titulo: 'Cómo crear una plantilla de WhatsApp',
        resumen: 'Paso a paso para crear una plantilla, enviarla a revisión de Meta y dejarla lista para usar en campañas, facturas o recibos.',
        icono: FileType,
        tema: 'Plantillas',
        tiempos: {
            total: '5 a 10 minutos llenarla + la revisión de Meta',
            filas: [
                ['Llenar el formulario', '5 a 10 minutos'],
                ['Subir el PDF o la imagen de muestra', 'Unos segundos'],
                ['Revisión de Meta', 'Normalmente minutos. Puede tardar hasta 24 horas'],
                ['Usarla en facturas o recibos (Integraciones)', '1 a 2 minutos'],
                ['Si la editas después de aprobada', 'Vuelve a revisión. Solo 1 edición cada 24 horas y 10 al mes'],
            ],
        },
        quien: 'Un usuario con permiso para crear plantillas (los administradores lo tienen).',
        antes: [
            'Tener el número de WhatsApp conectado y activo en Configuración › Instancias.',
            'Saber para qué es: un aviso sobre la cuenta del cliente (factura, pago, corte) o una promoción.',
            'Tener el texto escrito. Si va a llevar la factura o el recibo, tener a mano un PDF cualquiera de ejemplo.',
            'Pensar un nombre corto, sin tildes, espacios ni mayúsculas. Por ejemplo: recordatorio_pago.',
        ],
        pasos: [
            {
                titulo: 'Abre Plantillas y pulsa «Nueva plantilla»',
                imagenes: [{ src: '/img/guias/crear-plantilla/01-nueva-plantilla.jpg', ancho: 1136, alto: 125, alt: 'Cabecera de Plantillas con el botón verde «Nueva plantilla» a la derecha' }],
                donde: 'Menú › Envíos › Plantillas',
                duracion: '10 segundos',
                cuerpo: (
                    <>
                        <p>
                            En el menú de la izquierda, dentro de <strong>Envíos</strong>, entra a <strong>Plantillas</strong>.
                            Arriba a la derecha pulsa el botón verde <strong>Nueva plantilla</strong>.
                        </p>
                        <p>
                            Se abre un formulario de dos pasos, <strong>Configuración</strong> y <strong>Contenido</strong>. A la
                            derecha verás una vista previa que muestra cómo le llegará el mensaje al cliente.
                        </p>
                    </>
                ),
                consejo: 'Antes de crearla, mira el listado: puede que ya exista una aprobada que te sirva. Buscar tarda segundos; una nueva puede tardar horas.',
            },
            {
                titulo: 'Elige la categoría',
                imagenes: [{ src: '/img/guias/crear-plantilla/02-categoria.jpg', ancho: 732, alto: 380, alt: 'Las tres categorías con «Utilidad» elegida, y la casilla para permitir que Meta reclasifique' }],
                donde: 'Paso 1 · Configuración › Categoría',
                duracion: '30 segundos',
                cuerpo: (
                    <>
                        <p>Hay tres opciones. Elige la que describe de verdad el mensaje:</p>
                        <ul>
                            <li>
                                <strong>Utilidad</strong>: avisos sobre la cuenta o un pedido del cliente, como una factura
                                generada, un pago recibido, un recordatorio de pago o un corte de servicio.
                            </li>
                            <li>
                                <strong>Marketing</strong>: promociones, descuentos, novedades o cualquier mensaje para vender.
                            </li>
                            <li>
                                <strong>Autenticación</strong>: sólo para códigos de verificación de un solo uso.
                            </li>
                        </ul>
                        <p>
                            Abajo hay una casilla, <strong>«Permitir que Meta reclasifique la categoría si no coincide con el
                            contenido»</strong>. Si la marcas y Meta cree que tu mensaje de Utilidad es en realidad Marketing,
                            le cambia la categoría en vez de rechazarlo.
                        </p>
                    </>
                ),
                aviso: 'Si el texto de una plantilla de Utilidad suena a promoción («aprovecha», «descuento», «no te lo pierdas»), Meta la rechaza o la pasa a Marketing. Marketing cuesta más por mensaje y el cliente puede silenciarla.',
            },
            {
                titulo: 'Elige el número (si tienes más de uno)',
                donde: 'Paso 1 · Configuración › Instancia (WABA)',
                duracion: '10 segundos',
                cuerpo: (
                    <>
                        <p>
                            Si tu empresa tiene varias líneas de WhatsApp aparece el campo <strong>Instancia (WABA)</strong>.
                            Elige el número desde el que se va a enviar la plantilla. Si sólo tienes una línea, este campo no
                            sale.
                        </p>
                    </>
                ),
                aviso: 'Las plantillas son de cada número. Una plantilla aprobada en un número no existe en otro: si cambias de número, hay que volver a crearla allí.',
            },
            {
                titulo: 'Escribe el nombre',
                imagenes: [{ src: '/img/guias/crear-plantilla/04-nombre-idioma.jpg', ancho: 732, alto: 380, alt: 'Nombre «recordatorio_pago» escrito y el idioma «es — Español» elegido' }],
                donde: 'Paso 1 · Configuración › Nombre',
                duracion: '30 segundos',
                cuerpo: (
                    <>
                        <p>
                            Sólo se permiten <strong>minúsculas, números y guion bajo</strong> ( _ ). Nada de espacios, tildes,
                            eñes ni mayúsculas. Si escribes una mayúscula, el campo la pasa a minúscula solo.
                        </p>
                        <p>
                            Ejemplos correctos: <code>recordatorio_pago</code>, <code>factura_mensual</code>,
                            {' '}<code>corte_servicio_2</code>. El cliente nunca ve el nombre: es para reconocerla tú.
                        </p>
                    </>
                ),
                aviso: 'El nombre no se puede cambiar después. Y si borras una plantilla, Meta no deja volver a usar ese mismo nombre durante un tiempo: elige uno que te sirva a largo plazo.',
            },
            {
                titulo: 'Elige el idioma y pulsa «Siguiente»',
                imagenes: [{ src: '/img/guias/crear-plantilla/05-siguiente.jpg', ancho: 150, alto: 92, alt: 'Botón «Siguiente» en la barra inferior del formulario' }],
                donde: 'Paso 1 · Configuración › Idioma',
                duracion: '10 segundos',
                cuerpo: (
                    <>
                        <p>
                            Para clientes en Colombia elige <strong>es — Español</strong>. Luego pulsa <strong>Siguiente</strong>,
                            abajo a la derecha.
                        </p>
                        <p>
                            Si el botón no avanza, el formulario marca en rojo el campo que falta: casi siempre es el nombre
                            con un espacio o una tilde.
                        </p>
                    </>
                ),
                consejo: 'El idioma es parte de la plantilla: «recordatorio_pago» en es y en es_CO son dos plantillas distintas para Meta. Si tu software administrativo la va a usar, el nombre y el idioma tienen que coincidir exactamente.',
            },
            {
                titulo: 'Deja el tipo de variable en «Número»',
                imagenes: [{ src: '/img/guias/crear-plantilla/06-tipo-variable.jpg', ancho: 348, alto: 90, alt: 'Selector «Tipo de variable» con «Número» elegido' }],
                donde: 'Paso 2 · Contenido › Tipo de variable',
                duracion: '5 segundos',
                cuerpo: (
                    <>
                        <p>
                            Las variables son los huecos que se rellenan con los datos de cada cliente: el nombre, el valor,
                            la fecha. Con <strong>Número</strong> se escriben <code>{'{{1}}'}</code>, <code>{'{{2}}'}</code>,
                            {' '}<code>{'{{3}}'}</code>…
                        </p>
                        <p>
                            Déjalo en <strong>Número</strong>: es el formato que usan el software administrativo y las
                            campañas. «Nombre» (<code>{'{{cliente}}'}</code>) sólo tiene sentido si otra integración lo pide.
                        </p>
                    </>
                ),
            },
            {
                titulo: 'Elige el encabezado',
                imagenes: [{ src: '/img/guias/crear-plantilla/07-encabezado-documento.jpg', ancho: 690, alto: 190, alt: 'Encabezado «Documento» elegido y el recuadro para subir el PDF de muestra' }],
                donde: 'Paso 2 · Contenido › Muestra de contenido multimedia',
                duracion: '1 minuto',
                cuerpo: (
                    <>
                        <p>El encabezado es opcional. Tienes estas opciones:</p>
                        <ul>
                            <li><strong>Ninguna</strong>: sólo texto. Puedes poner un título corto (máximo 60 caracteres).</li>
                            <li>
                                <strong>Documento</strong>: para enviar la <strong>factura o el recibo en PDF</strong>. Es la
                                que hay que elegir si la plantilla va a llevar la factura adjunta.
                            </li>
                            <li><strong>Imagen</strong> (JPG o PNG) o <strong>Video</strong> (MP4): para campañas.</li>
                            <li><strong>Ubicación</strong>: para mandar un punto en el mapa.</li>
                        </ul>
                        <p>
                            Si eliges Documento, Imagen o Video, sube un archivo de muestra. Aparece «Subiendo a Meta…» y en
                            unos segundos queda cargado.
                        </p>
                    </>
                ),
                consejo: 'El archivo de muestra sólo lo usa Meta para revisar la plantilla. A cada cliente le llega su propio PDF o su propia imagen en cada envío, no esta muestra.',
                aviso: 'Una plantilla sin encabezado de Documento no puede llevar la factura adjunta. Si es para facturas o recibos y la creas sin él, habrá que crear otra.',
            },
            {
                titulo: 'Escribe el cuerpo del mensaje',
                imagenes: [{ src: '/img/guias/crear-plantilla/08-cuerpo.jpg', ancho: 736, alto: 251, alt: 'Cuerpo del mensaje con las variables {{1}}, {{2}} y {{3}} y el botón «Agregar variable»' }],
                donde: 'Paso 2 · Contenido › Cuerpo',
                duracion: '3 a 5 minutos',
                cuerpo: (
                    <>
                        <p>
                            Es el texto que lee el cliente, con un máximo de <strong>1.024 caracteres</strong>. Para meter un
                            dato del cliente pulsa <strong>Agregar variable</strong>: pone <code>{'{{1}}'}</code>, luego
                            {' '}<code>{'{{2}}'}</code> y así sucesivamente, siempre en orden y sin saltarse números.
                        </p>
                        <p>Un ejemplo que Meta aprueba sin problema:</p>
                        <blockquote>
                            Hola {'{{1}}'}, te recordamos que tu factura del mes por valor de {'{{2}}'} vence el {'{{3}}'}.
                            Puedes pagarla en nuestros puntos autorizados o por transferencia. Gracias por confiar en
                            nosotros.
                        </blockquote>
                        <p>
                            La barra de arriba del cuadro sirve para poner emojis, <strong>*negrita*</strong>,
                            {' '}<em>_cursiva_</em> y ~tachado~.
                        </p>
                    </>
                ),
                aviso: 'Lo que más hace rechazar una plantilla: empezar o terminar el mensaje con una variable, poner muchas variables y poco texto, o que el texto sea una promoción disfrazada de aviso.',
            },
            {
                titulo: 'Llena el ejemplo de cada variable',
                imagenes: [{ src: '/img/guias/crear-plantilla/09-ejemplos.jpg', ancho: 686, alto: 206, alt: 'Ejemplos de las variables: Juan Pérez, $85.000 y 15 de octubre' }],
                donde: 'Paso 2 · Contenido › debajo del cuerpo',
                duracion: '1 minuto',
                cuerpo: (
                    <>
                        <p>
                            Por cada <code>{'{{n}}'}</code> aparece un campo <strong>«Ejemplo para {'{{n}}'}»</strong>. Escribe
                            un valor realista: <code>Juan Pérez</code>, <code>$85.000</code>, <code>15 de octubre</code>.
                        </p>
                        <p>
                            Meta lee estos ejemplos para entender el mensaje. No se envían a nadie. Sin ellos el formulario no
                            deja enviar la plantilla.
                        </p>
                    </>
                ),
                consejo: 'Unos ejemplos reales ayudan a que la aprueben rápido. «xxx» o «prueba» hacen que Meta no entienda el mensaje y lo rechace.',
            },
            {
                titulo: 'Agrega el pie de página y los botones (opcional)',
                imagenes: [{ src: '/img/guias/crear-plantilla/10-pie-y-botones.jpg', ancho: 690, alto: 198, alt: 'Pie de página escrito y el botón «Agregar botón»' }],
                donde: 'Paso 2 · Contenido › Pie de página y Botones',
                duracion: '1 a 2 minutos',
                cuerpo: (
                    <>
                        <p>
                            El <strong>pie de página</strong> es una línea gris al final, de máximo 60 caracteres y sin
                            variables. Por ejemplo: «Nac Technology · Servicio al cliente».
                        </p>
                        <p>Con <strong>Agregar botón</strong> puedes poner hasta 10 botones:</p>
                        <ul>
                            <li><strong>Respuesta rápida</strong>: el cliente toca y responde con ese texto.</li>
                            <li><strong>URL</strong>: abre una página, por ejemplo el enlace de pago. Máximo 2.</li>
                            <li><strong>Teléfono</strong>: llama a un número. Máximo 1.</li>
                            <li><strong>Copiar código</strong>: copia un código, por ejemplo una referencia de pago. Máximo 1.</li>
                        </ul>
                    </>
                ),
            },
            {
                titulo: 'Revisa la vista previa y pulsa «Enviar para revisión»',
                imagenes: [{ src: '/img/guias/crear-plantilla/11a-vista-previa.jpg', ancho: 404, alto: 565, alt: 'Vista previa de la plantilla como le llega al cliente, con el PDF, el texto, el pie y el botón «Ya pagué»' }, { src: '/img/guias/crear-plantilla/11b-enviar.jpg', ancho: 312, alto: 92, alt: 'Botón «Enviar para revisión» en la barra inferior' }],
                donde: 'Barra inferior del formulario',
                duracion: '1 minuto',
                cuerpo: (
                    <>
                        <p>
                            Lee la vista previa de la derecha como si fueras el cliente. Si todo está bien, pulsa
                            {' '}<strong>Enviar para revisión</strong>.
                        </p>
                        <p>
                            Sale la pantalla <strong>«Plantilla enviada a Meta»</strong>, con el estado <strong>PENDING</strong>
                            {' '}(en revisión) y el número de la plantilla. Ya no tienes que hacer nada más para que Meta la revise.
                        </p>
                    </>
                ),
                aviso: 'Si en vez de esa pantalla sale un mensaje en rojo arriba, la plantilla no se envió. El mensaje dice qué corregir: corrígelo y vuelve a pulsar el botón.',
            },
            {
                titulo: 'Espera la aprobación de Meta',
                donde: 'Menú › Envíos › Plantillas',
                duracion: 'Minutos; hasta 24 horas',
                cuerpo: (
                    <>
                        <p>
                            La plantilla aparece en el listado con el idioma en naranja y la marca <strong>En revisión</strong>.
                            Pulsa <strong>Actualizar</strong> para ver si cambió. Lo normal es que Meta responda en pocos
                            minutos, pero a veces tarda unas horas, y como máximo 24.
                        </p>
                        <ul>
                            <li><strong>Aprobada</strong> (verde): ya se puede usar.</li>
                            <li><strong>Rechazada</strong> (rojo): mira abajo, «Si Meta la rechaza».</li>
                        </ul>
                    </>
                ),
                aviso: 'Mientras está en revisión no se puede enviar. No la uses en una campaña ni en los envíos automáticos hasta que esté en verde.',
            },
            {
                titulo: 'Si es para facturas o recibos: elígela en Integraciones',
                donde: 'Menú › Configuración › Integraciones › Envíos automáticos',
                duracion: '1 a 2 minutos',
                cuerpo: (
                    <>
                        <p>
                            Si la plantilla es para que el software administrativo mande facturas o recibos, aprobada no es
                            suficiente: hay que decirle que la use.
                        </p>
                        <ol>
                            <li>
                                En <strong>Envíos automáticos</strong>, abre el desplegable de <strong>Facturas</strong> o
                                {' '}<strong>Recibos de pago</strong>.
                            </li>
                            <li>
                                Búscala en el grupo de abajo, el que empieza por <strong>«Aprobadas en»</strong> y tu número, y
                                elígela. Queda registrada en el software sola.
                            </li>
                            <li>
                                Se abre <strong>Variables</strong>: di qué dato va en cada <code>{'{{n}}'}</code> (nombre del
                                cliente, total de la factura, fecha de vencimiento…) y guarda.
                            </li>
                        </ol>
                    </>
                ),
                aviso: 'Si no asignas las variables, la factura sale con los datos equivocados o Meta la rechaza envío a envío.',
            },
        ],
        rechazo: {
            titulo: 'Si Meta la rechaza',
            cuerpo: (
                <>
                    <p>
                        En Plantillas, toca la etiqueta del idioma en rojo (la que dice <strong>es · Rechazada</strong>). Se
                        abre la plantilla: en la pestaña <strong>Detalle</strong> sale el <strong>motivo de rechazo</strong>.
                        Los más comunes:
                    </p>
                    <ul>
                        <li>El texto parece una promoción y la categoría es Utilidad.</li>
                        <li>El mensaje empieza o termina con una variable.</li>
                        <li>Hay muchas variables para tan poco texto.</li>
                        <li>Tiene enlaces acortados o que parecen sospechosos.</li>
                        <li>Los ejemplos de las variables no se entienden.</li>
                    </ul>
                    <p>
                        En esa misma ventana, arriba a la derecha, pulsa <strong>Editar</strong>, corrige lo que dice el motivo
                        y pulsa <strong>Guardar y enviar a revisión</strong>. Entra de nuevo a revisión y tarda lo mismo que la
                        primera vez.
                    </p>
                </>
            ),
        },
        editar: 'Una plantilla aprobada se puede editar, pero vuelve a revisión y mientras tanto no se puede enviar. Meta sólo deja editarla una vez cada 24 horas y 10 veces al mes, y no deja cambiarle la categoría. Si la usas para facturas, edítala fuera del horario en que salen.',
        compartir: [
            'Para crear una plantilla de WhatsApp:',
            '1. Entra a Envíos › Plantillas y pulsa «Nueva plantilla».',
            '2. Elige la categoría: Utilidad para avisos de cuenta (facturas, pagos, cortes) o Marketing para promociones.',
            '3. Escribe el nombre en minúsculas y con guion bajo (ej. recordatorio_pago) y elige el idioma «es — Español». Pulsa «Siguiente».',
            '4. Si va a llevar la factura en PDF, en el encabezado elige «Documento» y sube un PDF de muestra.',
            '5. Escribe el mensaje. Usa «Agregar variable» para los datos del cliente ({{1}}, {{2}}…) y llena un ejemplo de cada una.',
            '6. Pulsa «Enviar para revisión».',
            '',
            'Llenarla toma entre 5 y 10 minutos. Meta la revisa normalmente en minutos, y como máximo en 24 horas. Cuando aparezca «Aprobada» ya se puede usar.',
        ].join('\n'),
    },
];

export const guiaPorSlug = slug => GUIAS.find(g => g.slug === slug) ?? null;
