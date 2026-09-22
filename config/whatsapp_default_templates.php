<?php

/**
 * Catálogo de plantillas por defecto de Integra CRM.
 *
 * A diferencia de las plantillas normales (que viven únicamente en Meta, por
 * WABA), este catálogo se define una sola vez para todo el producto. Cada
 * empresa lo sincroniza contra su propio WABA desde
 * Plantillas > Plantillas por defecto.
 *
 * ## Las que sólo salen con Integra conectado
 *
 * `requiere_integra` esconde la plantilla de quien no tiene el ERP conectado.
 * No es una restricción comercial: son plantillas que envía el propio ERP con
 * el PDF de la factura o de la tirilla adjunto, así que sin Integra no hay quien
 * las dispare ni documento que mandar. Ofrecérselas a quien no puede usarlas es
 * darle trabajo —aprobarlas en Meta tarda— a cambio de nada.
 *
 * Salen marcadas con `origen` para que el cliente sepa de dónde vienen: son
 * suyas en su cuenta de Meta, pero el texto y el envío los pone Integra.
 *
 * ## El archivo de muestra
 *
 * Un encabezado de tipo documento no se aprueba sin un ejemplo: Meta exige un
 * `header_handle`, y el handle está atado al WABA que subió el archivo. Por eso
 * `sample_file` es una ruta a un PDF del repositorio que se sube **en el momento
 * de sincronizar**, contra la cuenta de esa empresa. Ese PDF no se le manda a
 * ningún cliente: sólo sirve para que Meta vea qué forma tiene el adjunto.
 */
return [
    'reanudar_conversacion_cliente' => [
        'label' => 'Reanudar conversación con el cliente',
        'description' => 'Reabre una conversación cuando la ventana de 24h del cliente ya expiró.',
        'category' => 'UTILITY',
        'language' => 'es',
        'parameter_format' => 'POSITIONAL',
        'components' => [
            [
                'type' => 'BODY',
                'text' => "Hola {{1}}, esperamos que te encuentres muy bien.\n\nNos ponemos en contacto para dar continuidad a tu solicitud relacionada con {{2}}.\n\nSi aún necesitas ayuda o deseas continuar con el proceso, responde a este mensaje y con gusto te atenderemos.",
                'example' => [
                    'body_text' => [['Juan Pérez', 'tu servicio de internet']],
                ],
            ],
        ],
        'variable_hints' => [
            '1' => 'Nombre del cliente',
            '2' => 'Motivo de la conversación (ej. "tu servicio de internet", "tu instalación", "tu factura", "tu soporte técnico", "tu cotización")',
        ],
    ],

    /**
     * Respaldo de los avisos automáticos que entran por la API pública
     * (/api/v1/messages/send). Fuera de la ventana de 24h Meta sólo acepta
     * plantillas, así que el texto libre del ERP se envuelve en ésta en vez de
     * perderse. El nombre del negocio va como variable para que la misma
     * definición sirva a todas las empresas: quien la recibe ve su proveedor,
     * no "Integra CRM".
     *
     * `auto_fill` es el contrato de relleno: qué va en cada {{n}} cuando el
     * sistema la envía solo. Ver WhatsAppFallbackTemplateService.
     */
    'aviso_automatico_cliente' => [
        'label' => 'Aviso automático fuera de la ventana de 24h',
        'description' => 'Respaldo de los avisos automáticos (facturas, pagos, recordatorios) cuando el cliente no ha escrito en 24h y WhatsApp ya no acepta texto libre.',
        'category' => 'UTILITY',
        'language' => 'es',
        'parameter_format' => 'POSITIONAL',
        'components' => [
            [
                'type' => 'BODY',
                'text' => "Hola, te compartimos un aviso de *{{1}}*:\n\n{{2}}\n\nSi tienes alguna consulta, responde a este mensaje y con gusto te atenderemos.",
                'example' => [
                    'body_text' => [['MEGASTORE', 'Su soporte de pago ha sido generado bajo el Nro. 6780']],
                ],
            ],
        ],
        'variable_hints' => [
            '1' => 'Nombre del negocio que envía el aviso',
            '2' => 'Texto del aviso generado por el sistema externo',
        ],
        'auto_fill' => ['business_name', 'message'],
    ],

    /*
    |--------------------------------------------------------------------------
    | El aviso operativo que escribe una persona
    |--------------------------------------------------------------------------
    |
    | Las dos de arriba las dispara el sistema. Faltaba la de mandar un aviso a
    | mano —un corte, un mantenimiento, un cambio de canales— fuera de la
    | ventana de 24 h, que es justo cuando hace falta: si el cliente no ha
    | escrito hoy, WhatsApp no acepta texto libre y el asesor se queda sin
    | forma de avisarle.
    |
    | Sin una plantilla así lo que se acaba usando es la de marketing que haya
    | a mano, y eso no es un detalle de formulario: Meta cobra las de marketing
    | más caras, no las entrega a quien tenga silenciadas las promociones y
    | castiga la calidad del número cuando la gente las marca como no deseadas.
    | Un aviso del servicio es UTILITY y va por otro carril.
    |
    | El texto es el que Comuna13 tiene aprobado y lleva meses enviando,
    | copiado carácter por carácter —espacios finales incluidos— por lo mismo
    | que las dos de abajo: una plantilla aprobada es un activo, y cualquier
    | retoque la devuelve a la cola de revisión de Meta.
    |
    */

    'notificaciones' => [
        'label' => 'Notificación al cliente',
        'description' => 'Un aviso operativo del servicio —un corte, un mantenimiento, un cambio— '
            .'que el asesor escribe y envía aunque el cliente no haya escrito en 24h. '
            .'No sirve para promociones: eso es marketing y va en otra plantilla.',
        'category' => 'UTILITY',
        'language' => 'es_CO',
        'components' => [
            [
                'type' => 'HEADER',
                'format' => 'TEXT',
                'text' => 'Notificaciones.',
            ],
            [
                // El emoji va escapado y los espacios del final de línea están
                // puestos a propósito: es el texto aprobado tal cual, y aquí un
                // carácter de diferencia es otra plantilla.
                'type' => 'BODY',
                'text' => "Usuario \u{2139}\u{FE0F} \n{{1}} \nEste mensaje corresponde a información operativa de su servicio activo.\nGracias.",
                'example' => [
                    'body_text' => [['Se le informa que debido a una actualización de canales que hicimos recientemente se debe realizar la búsqueda automática de canales en su televisor...']],
                ],
            ],
        ],
        'variable_hints' => [
            '1' => 'El aviso que quieres dar (ej. «Mañana de 8:00 a 11:00 habrá mantenimiento programado en tu sector»)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Las dos de Integra: la factura y la tirilla del pago
    |--------------------------------------------------------------------------
    |
    | Copiadas del texto que Comuna13 ya tiene aprobado en producción y que
    | lleva meses enviándose. No se reescribió «mejor»: una plantilla aprobada
    | es un activo —Meta puede tardar días y rechazar por matices— y el texto
    | que ya pasó es el que se sabe que pasa.
    |
    | Van en `es_CO` por lo mismo: es el idioma con el que están aprobadas allí.
    |
    */

    'facturacion' => [
        'label' => 'Factura generada',
        'description' => 'Avisa al cliente de que se le generó la factura, con el PDF adjunto. La envía Integra cuando factura.',
        'category' => 'UTILITY',
        'language' => 'es_CO',
        'requiere_integra' => true,
        'origen' => 'Integra',
        'sample_file' => 'resources/plantillas/muestra-documento.pdf',
        'components' => [
            [
                'type' => 'HEADER',
                'format' => 'DOCUMENT',
            ],
            [
                'type' => 'BODY',
                'text' => "Estimado cliente {{1}}. {{2}} le informa que se ha generado su factura por valor de $ {{3}}, recuerda hacer el pago de la factura de manera oportuna {{4}}\n\nGracias por confiar en nuestros servicios.",
                'example' => [
                    'body_text' => [['Juan Pérez', 'COMUNA13', '65.000', 'antes del 25 de septiembre']],
                ],
            ],
        ],
        'variable_hints' => [
            '1' => 'Nombre del cliente',
            '2' => 'Nombre del negocio que factura',
            '3' => 'Valor de la factura',
            '4' => 'Plazo de pago (ej. «antes del 25 de septiembre»)',
        ],
    ],

    'tirilla' => [
        'label' => 'Pago recibido (tirilla)',
        'description' => 'Confirma al cliente que su pago quedó registrado, con el comprobante adjunto. La envía Integra al procesar el pago.',
        'category' => 'UTILITY',
        'language' => 'es_CO',
        'requiere_integra' => true,
        'origen' => 'Integra',
        'sample_file' => 'resources/plantillas/muestra-documento.pdf',
        'components' => [
            [
                'type' => 'HEADER',
                'format' => 'DOCUMENT',
            ],
            [
                'type' => 'BODY',
                'text' => 'Estimado cliente {{1}}. {{2}} le informa que se ha procesado el pago de ${{3}}, puedes verificar el pago en el siguiente documento.',
                'example' => [
                    'body_text' => [['Juan Pérez', 'COMUNA13', '65.000']],
                ],
            ],
        ],
        'variable_hints' => [
            '1' => 'Nombre del cliente',
            '2' => 'Nombre del negocio que recibe el pago',
            '3' => 'Valor pagado',
        ],
    ],
];
