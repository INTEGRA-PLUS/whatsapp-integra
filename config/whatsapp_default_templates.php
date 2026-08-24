<?php

/**
 * Catálogo de plantillas por defecto de Integra CRM.
 *
 * A diferencia de las plantillas normales (que viven únicamente en Meta, por
 * WABA), este catálogo se define una sola vez para todo el producto. Cada
 * empresa lo sincroniza contra su propio WABA desde
 * Plantillas > Plantillas por defecto, así que está disponible para todas
 * las empresas sin ninguna bandera por compañía.
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
];
