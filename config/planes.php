<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dos cosas distintas: el CRM y la IA
    |--------------------------------------------------------------------------
    |
    | Hasta el 15-sep-2026 esto eran tres planes —Esencial, Automatización,
    | Inteligente— con la IA metida dentro del más caro. Se rehízo al descubrir
    | que casi toda la base llegó con Integra y **ya paga el CRM dentro del
    | ERP**: los 2.391 USD/mes que el panel contaba como facturación potencial
    | eran de gente que ya pagaba.
    |
    | Con eso claro, el producto son dos cosas que se venden por separado:
    |
    | - **El plan de CRM** decide el tamaño: cuántos agentes, cuántos contactos,
    |   cuántas líneas. Al cliente de Integra no se le cobra —lo tiene dentro de
    |   su ERP— y al que llega sin ERP sí.
    | - **El complemento de IA** decide qué funciones con modelo se encienden.
    |   Se le vende a todos, vengan de donde vengan, y es donde está la ganancia
    |   nueva: cuesta céntimos y se cobra en decenas.
    |
    | Precio fijo por plan y no un rango por tramo, como pidió Alejandro y como
    | lo hace TecnoChat. Un rango se lee como «depende» o como negociable; un
    | número se dice en la mesa.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Los planes de CRM
    |--------------------------------------------------------------------------
    |
    | `agentes`, `contactos` y `lineas` son lo que incluye el plan. **No son
    | límites duros**: nadie deja de atender a un cliente porque la empresa
    | creció. Sirven para saber cuándo toca hablar de subir de plan, y salen en
    | el panel cuando alguien se pasa.
    |
    | Los topes se eligieron contra la base real, no a ojo: con 2 agentes y
    | 3.000 contactos, **36 de los 41 clientes caben en el Básico**. Los cinco
    | que se pasan son Star NET, Comuna13, Megastore, InterSolar y REINTECH.
    |
    | El precio del Básico se fijó mirando a TecnoChat, que a 27 USD da 1 agente
    | y a 57 da 1 admin + 2 agentes. Aquí son 29 por dos agentes — y con el ERP
    | integrado, que ellos no tienen.
    |
    | `credito_ia` va en el plan de CRM y no en el complemento porque es un
    | número que depende del **tamaño del cliente**, no de qué funciones tenga
    | encendidas. El complemento decide qué se enciende; el plan, cuánto cabe.
    |
    */

    'crm' => [

        'basico' => [
            'nombre' => 'Básico',
            'precio' => 29,
            'agentes' => 2,
            'contactos' => 3000,
            'lineas' => 1,
            'credito_ia' => 1500,
        ],

        'pro' => [
            'nombre' => 'Pro',
            'precio' => 59,
            'agentes' => 5,
            'contactos' => 10000,
            'lineas' => 2,
            'credito_ia' => 4000,
        ],

        'avanzado' => [
            'nombre' => 'Avanzado',
            'precio' => 109,
            'agentes' => 10,
            'contactos' => 30000,
            'lineas' => 3,
            'credito_ia' => 10000,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | El complemento de IA
    |--------------------------------------------------------------------------
    |
    | Dos niveles, y la razón de que sean dos es el **coste**, que no es parejo:
    | entre la función de IA más barata y la más cara hay un factor 13.
    |
    |   Semáforo con IA .... 0,00032 USD por análisis
    |   Resumen ............ 0,00090 USD
    |   Menús con IA ....... 0,00065 USD por mensaje
    |   Chat con IA ........ 0,00860 USD por conversación   ← 13x el semáforo
    |
    | Medido sobre el volumen real de los 41 clientes, **semáforo y resumen
    | juntos cuestan unos 30 USD al mes para toda la base**. El chat con IA, si
    | lo usaran todos, serían 352. Por eso van separados: el nivel barato se
    | puede vender con margen del 96% y el caro necesita medirse.
    |
    | `extensiones` son las que desbloquea **además de las del CRM**.
    | `ajustes` son campos concretos dentro de una extensión que ya se tiene: el
    | semáforo entra con el CRM, pero encender «usar_ia» exige complemento.
    | `flujos` son las integraciones de n8n que quedan disponibles.
    |
    */

    'ia' => [

        'ninguno' => [
            'nombre' => 'Sin IA',
            'precio' => 0,
            'extensiones' => [],
            'ajustes' => [],
            'flujos' => [],
        ],

        'esencial' => [
            'nombre' => 'IA Esencial',
            'precio' => 19,
            // Las dos que ya funcionan, están probadas y se enseñan en la demo.
            'extensiones' => ['conversation_summary', 'sentiment_traffic_light'],
            'ajustes' => ['sentiment_traffic_light' => ['usar_ia']],
            'flujos' => [],
        ],

        'completa' => [
            'nombre' => 'IA Completa',
            'precio' => 49,
            'extensiones' => ['conversation_summary', 'sentiment_traffic_light'],
            'ajustes' => ['sentiment_traffic_light' => ['usar_ia']],
            // Las caras: aquí es donde el crédito del plan empieza a importar.
            'flujos' => ['ai_menus', 'ai_chat'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Las extensiones que van con el CRM
    |--------------------------------------------------------------------------
    |
    | Las que no llaman a ningún modelo. Van en los tres planes de CRM: se
    | venden en paquete, nunca sueltas —un precio por extensión multiplica las
    | combinaciones que hay que cobrar, explicar y sostener—.
    |
    | **El cierre automático entra aquí** (17-sep-2026). No llama a ningún
    | modelo: reconoce la despedida con reglas y el resto son dos relojes. Y lo
    | que arregla es del núcleo, no de la IA —una bandeja donde «9 abiertas»
    | significa nueve abiertas—, así que además del reparto por carga se
    | beneficia cualquier empresa, tenga complemento o no.
    |
    | **El semáforo NO está aquí, y es una decisión comercial, no técnica.**
    | Técnicamente podría: su primera capa es un diccionario en PHP y colorea
    | sin llamar a ningún modelo. Estuvo en el CRM por eso, con el argumento de
    | que verlo funcionando es lo que hace que quieran la IA.
    |
    | Se movió al complemento el 15-sep-2026 por estrategia: es la función de IA
    | que mejor se ve —caritas de colores en la bandeja, sin tener que abrir
    | nada— y por tanto la que mejor la vende. Regalarla es regalar el mejor
    | escaparate que hay.
    |
    | El coste lo permite: son 0,00032 USD por análisis, ~26 USD al mes para
    | toda la base. Entra en el nivel Esencial, el de 19.
    |
    | Las tres empresas que lo tenían instalado al moverlo ya tenían complemento,
    | así que a nadie se le quitó nada.
    |
    | Una extensión que no esté ni aquí ni en ningún nivel de `ia` no la puede
    | instalar nadie, que es el comportamiento seguro al publicar una nueva: se
    | decide dónde entra antes de que aparezca, no después de que alguien ya la
    | tenga.
    |
    */

    'extensiones_del_crm' => [
        'agent_signature',
        'follow_up',
        'keyword_routing',
        'cierre_automatico',
    ],

    'nucleo' => [
        'Chat multiagente con historial completo',
        'Tablero Kanban de conversaciones',
        'Contactos, etiquetas y macros',
        'Menús de WhatsApp: el bot que responde por reglas',
        'Respuestas automáticas y respuestas rápidas',
        'Campañas y plantillas',
        'Reportes de atención',
        'Agentes y líneas ilimitados',
        'WhatsApp, Instagram y Facebook Messenger',
    ],

    /*
    |--------------------------------------------------------------------------
    | Descuento por pago anual
    |--------------------------------------------------------------------------
    |
    | Dos meses gratis, o sea diez mensualidades repartidas en doce. Todos en el
    | nicho facturan por trimestre o año adelantado, y con razón: el montaje
    | —conectar el número, armar plantillas, entrenar al equipo— no se recupera
    | en un mes. TecnoChat hace lo mismo.
    |
    */

    'meses_gratis_al_pagar_anual' => 2,

    /*
    |--------------------------------------------------------------------------
    | Tasa de cambio para facturar
    |--------------------------------------------------------------------------
    |
    | Los precios de este catálogo están en USD porque así se negocia y así se
    | compara con la competencia. Pero OnePay cobra en **pesos colombianos**, en
    | entero, y con un mínimo de 5.000 y un máximo de 100.000.000 por factura.
    |
    | La tasa es **fija y se pone a mano**, no se consulta en vivo a propósito.
    | Una tasa viva haría que el mismo plan costara distinto cada mes sin que
    | nadie lo hubiera decidido, que el cliente viera un importe que no cuadra
    | con lo que se le dijo, y que conciliar dos facturas seguidas fuera un
    | ejercicio de arqueología.
    |
    | Al cambiarla, los cobros ya emitidos no se mueven: el importe se guarda en
    | la fila, no se recalcula.
    |
    | **Qué número poner, y cuándo.** No se mira el dólar en Google: se ejecuta
    |
    |     php artisan tasas:vigilar --serie
    |
    | que enseña el histórico de la TRM oficial día a día y propone una tasa —el
    | techo de las últimas semanas, redondeado a 50 arriba— con la línea lista
    | para pegar aquí. Se elige el techo y no el promedio porque quedarse en el
    | promedio es cobrar de menos la mitad de los días.
    |
    | Ese mismo comando corre solo cada mañana a las 7:05 y, si la tasa se separó
    | más de un 8% de la TRM, `suscripciones:emitir` se para antes de crear nada.
    | Así que revisar esto es cosa de cuando el comando avisa, no de cada semana.
    |
    */

    'tasa_cop' => (int) env('PLANES_TASA_COP', 4000),

    /*
    |--------------------------------------------------------------------------
    | Ciclos de cobro
    |--------------------------------------------------------------------------
    |
    | `meses` es lo que dura el periodo; `mensualidades` es lo que se cobra. La
    | diferencia entre los dos es el descuento, y por eso van separados en vez de
    | un porcentaje: «doce meses por diez mensualidades» se entiende y se dice en
    | la mesa; «16,67% de descuento» hay que calcularlo delante del cliente.
    |
    | El trimestral no lleva descuento a propósito. Es el ciclo por defecto del
    | nicho —todos facturan por trimestre adelantado, porque el montaje no se
    | recupera en un mes— y regalar ahí quita margen al único descuento que de
    | verdad compra permanencia, que es el anual.
    |
    */

    'ciclos' => [
        'mensual' => ['nombre' => 'Mensual', 'meses' => 1, 'mensualidades' => 1],
        'trimestral' => ['nombre' => 'Trimestral', 'meses' => 3, 'mensualidades' => 3],
        'anual' => ['nombre' => 'Anual', 'meses' => 12, 'mensualidades' => 10],
    ],

    /*
    |--------------------------------------------------------------------------
    | Estados de cobro
    |--------------------------------------------------------------------------
    |
    | `integra` es el más importante y el que faltaba: la empresa llegó con
    | Integra y **el CRM va dentro de lo que ya paga por el ERP**. No se le
    | factura aquí, y no hay nada que revisar. Antes estaban todas en
    | `cortesia`, que significa otra cosa —un pendiente comercial— y garantizaba
    | que dentro de unos meses alguien intentara «regularizarlas» cobrándoles
    | dos veces lo mismo.
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

    'cobros' => ['integra', 'cortesia', 'prueba', 'activo', 'suspendido'],

];
