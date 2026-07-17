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
];
