<?php

namespace App\Extensions\Contracts;

use App\Models\CompanyExtension;

/**
 * Gancho programado: lo llama `extensions:run` cada cinco minutos, una vez por
 * cada empresa que tenga la extensión instalada y encendida.
 *
 * La extensión recibe SU fila, no un company_id suelto: de ahí salen los
 * ajustes y la empresa, y así no hay forma de escribir una consulta sin filtrar
 * por empresa sin darse cuenta.
 */
interface RunsOnSchedule
{
    public function runScheduled(CompanyExtension $installed): void;
}
