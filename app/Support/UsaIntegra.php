<?php

namespace App\Support;

use App\Models\Company;
use App\Services\Integra;

/**
 * ¿Esta empresa tiene algo que ver con Integra?
 *
 * Es la llave de **todo lo que menciona Integra en la interfaz**: los permisos
 * de la IA que lo consultan, los avisos de si está conectado, la plantilla de
 * ISP, la tarjeta de la IA de menús. Una farmacia o una barbería no deben leer
 * ni una palabra sobre un ERP de proveedores de internet: cada mención es una
 * pregunta que no saben responder («¿tengo que contratar eso?»).
 *
 * ## La señal que NO sirve, y por qué
 *
 * Lo primero que se probó fue «tiene opciones de autoservicio en su menú». No
 * escondía nada: el menú de fábrica antiguo se las sembró a **las 49 empresas**
 * de producción, así que la condición era verdadera siempre. Un cliente nuevo
 * que heredara ese menú leería sobre Integra igual.
 *
 * La señal buena es de negocio y no de configuración: **la empresa vino de
 * Integra**, o **lo tiene conectado**. Las dos cosas se saben sin mirar menús.
 *
 * Vive aquí y no en un controlador porque la usan dos pantallas —«Menús de
 * WhatsApp» y «IA que responde»— y una regla repetida en dos sitios es una
 * regla que dentro de tres meses dice cosas distintas en cada uno.
 */
class UsaIntegra
{
    public static function de(Company $company): bool
    {
        return (bool) $company->viene_de_integra
            || Integra::connected($company->id);
    }
}
