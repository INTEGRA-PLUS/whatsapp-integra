<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Los planes
    |--------------------------------------------------------------------------
    |
    | Aquí y no en base de datos, por lo mismo que el catálogo de extensiones
    | (`config/extensions.php`): esto es una regla de producto, y una fila que
    | pueda quedar desincronizada del catálogo de extensiones sólo añade formas
    | de fallar. Lo que sí va a base de datos es qué plan tiene cada empresa.
    |
    | `extensiones` es una lista de slugs, o `'*'` para todas. Un slug que no
    | esté en ningún plan no lo puede instalar nadie, que es el comportamiento
    | seguro al publicar una extensión nueva: se decide en qué plan entra antes
    | de que aparezca, no después de que alguien ya la tenga.
    |
    | `ia` son las conversaciones con IA incluidas al mes. `null` = sin IA.
    |
    */

    'disponibles' => [

        'esencial' => [
            'nombre' => 'Esencial',
            'extensiones' => [
                'agent_signature',
            ],
            'ia' => null,
        ],

        'automatizacion' => [
            'nombre' => 'Automatización',
            'extensiones' => [
                'agent_signature',
                'follow_up',
                'keyword_routing',
                // El semáforo entra aquí porque su capa 1 es un diccionario y
                // funciona sin modelo. El ajuste «Afinar con IA» es lo que se
                // bloquea en este plan, no la extensión entera: enseñar el
                // semáforo funcionando es lo que hace que quieran la IA.
                'sentiment_traffic_light',
            ],
            'ia' => null,
        ],

        'inteligente' => [
            'nombre' => 'Inteligente',
            'extensiones' => '*',
            // Se lleva al tramo contratado en `PlanDeLaEmpresa`; esto es el
            // suelo para una empresa sin tramo asignado.
            'ia' => 1200,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Conversaciones con IA por tramo de contactos
    |--------------------------------------------------------------------------
    |
    | El crédito incluido del plan Inteligente, según el tramo contratado. Son
    | aproximadamente la mitad del tope de cada tramo: medido contra el uso real,
    | nadie conversa con el bot todos los meses.
    |
    | Al agotarse **no se corta nada**: se factura el exceso. Cortar a mitad de
    | una conversación con un socio no compensa lo que se ahorra.
    |
    */

    'credito_ia' => [
        500 => 300,
        2000 => 1200,
        5000 => 3000,
        15000 => 8000,
        30000 => 16000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ajustes que exigen plan con IA
    |--------------------------------------------------------------------------
    |
    | Una extensión puede estar en un plan y tener dentro un ajuste que no. El
    | semáforo es el caso: la extensión entra en Automatización, pero encender
    | «usar_ia» no. Sin esto, el candado de la extensión se saltaría por el
    | formulario de ajustes.
    |
    */

    'ajustes_con_ia' => [
        'sentiment_traffic_light' => ['usar_ia'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Estados de cobro
    |--------------------------------------------------------------------------
    |
    | `cortesia` es el estado de las empresas que ya usaban el CRM cuando se
    | introdujeron los planes: todo encendido y sin factura, hasta que firmen.
    | Es un estado legítimo y permanente, no un limbo.
    |
    | `suspendido` NO apaga el CRM. Marca a quien no ha pagado para que aparezca
    | en el panel maestro. Apagarle el WhatsApp a una cooperativa un día de
    | recaudo por una factura de 300 dólares es la forma más cara de cobrar.
    |
    */

    'cobros' => ['cortesia', 'prueba', 'activo', 'suspendido'],

];
