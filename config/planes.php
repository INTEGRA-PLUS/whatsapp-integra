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
    | El precio NO vive aquí dentro, sino en `precios`, más abajo: un plan no
    | tiene un precio, tiene uno por cada tramo de socios. Meterlo en el plan
    | obligaría a inventar «el precio de Inteligente», que no existe — va de 65
    | a 419 USD según cuántos clientes atienda la empresa.
    |
    | Antes hubo escritos 49,99 / 129,99 / 499,99 USD a pelo en el JSX, que no
    | salían de ningún sitio y no correspondían a nada cobrado nunca. La
    | escalera de ahora sí: sale de `docs/producto-y-precios.md`, está anclada a
    | los 250 USD que se le propusieron a Cootramed —299 de lista menos los dos
    | meses del pago anual— y se fijó contra el suelo de la competencia en la
    | liga ISP (CRM Inbox, 257 USD sin IA a 12.000 contactos).
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
    | La escalera de precios
    |--------------------------------------------------------------------------
    |
    | USD al mes de tarifa de plataforma, por tramo de socios o contactos
    | activos. **No incluye los mensajes**: esos se los paga el cliente a Meta
    | directamente y nosotros no cobramos margen encima — es un argumento de
    | venta, y es verificable en su propia factura.
    |
    | Se cobra por socios y no por agentes porque es como piensa el cliente
    | («tengo 12.000 socios») y porque cobrar por agente castiga justo a quien
    | más usa la herramienta. Agentes y líneas van ilimitados en los tres.
    |
    | Los topes son los mismos que en `credito_ia`, y tienen que seguir
    | siéndolo: son el mismo tramo mirado desde dos sitios.
    |
    | Precio de lista, que es el trimestral. El anual lleva dos meses gratis, o
    | sea diez mensualidades: 299 de lista son 249 al mes pagando el año.
    |
    | Por encima del último tramo es «a cotizar» a propósito — y conviene, que
    | es donde el margen da para negociar.
    |
    */

    'precios' => [
        500 => ['esencial' => 35, 'automatizacion' => 49, 'inteligente' => 65],
        2000 => ['esencial' => 65, 'automatizacion' => 89, 'inteligente' => 119],
        5000 => ['esencial' => 109, 'automatizacion' => 149, 'inteligente' => 195],
        15000 => ['esencial' => 179, 'automatizacion' => 235, 'inteligente' => 299],
        30000 => ['esencial' => 259, 'automatizacion' => 339, 'inteligente' => 419],
    ],

    /*
    |--------------------------------------------------------------------------
    | Descuento por pago anual
    |--------------------------------------------------------------------------
    |
    | Dos meses gratis. Todos en el nicho facturan por trimestre o año
    | adelantado, y con razón: el montaje —conectar el número, armar plantillas,
    | entrenar al equipo— no se recupera en un mes.
    |
    */

    'meses_gratis_al_pagar_anual' => 2,

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
