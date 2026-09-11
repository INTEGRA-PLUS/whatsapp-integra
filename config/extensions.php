<?php

use App\Extensions\AgentSignatureExtension;
use App\Extensions\FollowUpExtension;
use App\Extensions\KeywordRoutingExtension;

return [

    /*
    |--------------------------------------------------------------------------
    | Catálogo de extensiones
    |--------------------------------------------------------------------------
    |
    | El catálogo de la tienda: qué extensiones existen en la plataforma. Está
    | aquí y no en base de datos porque una extensión es código ejecutable —su
    | comportamiento, su validación y su manifiesto viven en la misma clase—, y
    | una fila que pueda quedar desincronizada de la clase que dice ejecutar sólo
    | añade formas de fallar. Lo que sí va a base de datos es qué instaló cada
    | empresa (`company_extensions`).
    |
    | El orden importa en un caso: cuando varias extensiones filtran el texto
    | saliente, se encadenan en este orden. Reordenar la lista cambia el
    | resultado.
    |
    | Añadir una extensión = escribir la clase y ponerla aquí. Ni migración, ni
    | ruta, ni pantalla: las dos pantallas se generan a partir del manifiesto.
    |
    */

    'available' => [
        FollowUpExtension::class,
        KeywordRoutingExtension::class,
        AgentSignatureExtension::class,
    ],

];
