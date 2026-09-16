<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Collection;

/**
 * A quién hay que cobrarle este mes y cuánto.
 *
 * La factura **no se emite aquí**. Se decidió facturar desde donde ya se
 * factura Integra, así que lo único que el CRM tiene que dar es la lista: quién,
 * cuánto y por qué. Meter numeración fiscal, impuestos y pasarela en un CRM de
 * WhatsApp es construir un módulo contable de segunda para sustituir a uno que
 * ya funciona.
 *
 * ## Las tres razones por las que alguien NO sale en la lista
 *
 * Se distinguen a propósito en vez de juntarse en un «no factura», porque
 * significan cosas distintas y se corrigen de forma distinta:
 *
 * - **interna** — es nuestra. `Master Admin`, `PRUEBAS`, `Meta App Review`.
 *   Nunca va a pagar y no es un pendiente comercial.
 * - **integra** — llegó con el ERP y el CRM va dentro de lo que ya paga por
 *   él. Es un cliente que paga, sólo que por otra puerta, y su venta futura no
 *   es el CRM sino el complemento de IA. Sin este motivo aparte, la cuenta de
 *   facturación potencial del panel salía inflada: contaba como cobrable a
 *   gente que ya paga.
 * - **cortesía / prueba** — cliente al que se decidió no cobrarle todavía. Es
 *   un pendiente comercial que alguien tiene que revisar.
 * - **mes gratis** — va a pagar, pero no este mes. Vuelve solo.
 *
 * Y una cuarta que sí sale en la lista pero avisando: **sin tramo asignado**.
 * Es un cliente al que nadie le puso precio; sale con «a cotizar» en vez de
 * desaparecer, porque desaparecer es como se deja de cobrar a alguien durante
 * un año sin que nadie lo note.
 *
 * ## Lo que no hace
 *
 * No apaga a nadie, no marca a nadie como moroso y no cambia ningún estado.
 * Es una consulta.
 */
class CobroDelMes
{
    /**
     * @return array{
     *     cobrar: list<array<string, mixed>>,
     *     fuera: list<array<string, mixed>>,
     *     total_usd: int,
     *     sin_tramo: int
     * }
     */
    public static function calcular(): array
    {
        $empresas = Company::query()->orderBy('name')->get();

        $cobrar = [];
        $fuera = [];
        $total = 0;
        $sinTramo = 0;

        foreach ($empresas as $company) {
            $plan = PlanDeLaEmpresa::de($company);

            if ($motivo = self::porQueNoSeCobra($company, $plan)) {
                $fuera[] = [
                    'id' => $company->id,
                    'empresa' => $company->name,
                    'motivo' => $motivo,
                    'nota' => $company->nota_de_cobro,
                    // Hasta cuándo dura la gracia, para que el mes gratis no se
                    // convierta en gratis para siempre por olvido.
                    'hasta' => optional($company->gratis_hasta)->toDateString(),
                ];

                continue;
            }

            $precio = $plan->precioMensual();

            if ($precio === null) {
                $sinTramo++;
            }

            $total += (int) $precio;

            $cobrar[] = [
                'id' => $company->id,
                'empresa' => $company->name,
                'plan' => $plan->nombre(),
                'tramo' => $company->contactos_contratados,
                'contactos_reales' => $plan->contactosReales(),
                'se_paso' => $plan->sePasoDelTramo(),
                'usd' => $precio,
                'usd_anual' => $plan->precioMensualAnual(),
                'nota' => $company->nota_de_cobro,
            ];
        }

        return [
            'cobrar' => $cobrar,
            'fuera' => $fuera,
            'total_usd' => $total,
            'sin_tramo' => $sinTramo,
        ];
    }

    /**
     * El motivo por el que esta empresa no entra en la facturación, o null si
     * sí entra.
     *
     * El orden importa: una empresa interna que además está en cortesía se
     * reporta como interna, porque es lo que explica de verdad por qué no paga
     * y lo que impide que alguien «arregle» su cobro más adelante.
     */
    private static function porQueNoSeCobra(Company $company, PlanDeLaEmpresa $plan): ?string
    {
        if ($company->interna) {
            return 'interna';
        }

        // Antes que cortesía a propósito: son las dos formas de «aquí no se le
        // factura» y significan cosas opuestas. Integra es un cliente que paga;
        // cortesía es uno al que todavía no se le cobra. Si Integra saliera como
        // cortesía, alguien la pasaría a activo en la siguiente revisión y le
        // cobraría dos veces el mismo CRM.
        if ($plan->incluidoEnIntegra()) {
            return 'integra';
        }

        if (in_array($plan->cobro(), ['cortesia', 'prueba'], true)) {
            return $plan->cobro();
        }

        if ($plan->enMesGratis()) {
            return 'mes_gratis';
        }

        return null;
    }

    /**
     * La lista de cobro como CSV.
     *
     * Separador `;` y BOM: es lo que abre Excel en español sin preguntar por el
     * delimitador ni romper las tildes, que es donde se atasca quien recibe el
     * archivo.
     */
    public static function csv(): string
    {
        $datos = self::calcular();

        $lineas = new Collection(["\u{FEFF}Empresa;Plan;Tramo;Contactos reales;USD mes;USD mes pagando anual;Aviso;Nota"]);

        foreach ($datos['cobrar'] as $fila) {
            $lineas->push(implode(';', [
                self::limpio($fila['empresa']),
                $fila['plan'],
                $fila['tramo'] ?: 'sin tramo',
                $fila['contactos_reales'],
                $fila['usd'] ?? 'a cotizar',
                $fila['usd_anual'] ?? 'a cotizar',
                $fila['se_paso'] ? 'pasado del tramo' : '',
                self::limpio((string) $fila['nota']),
            ]));
        }

        $lineas->push('');
        $lineas->push('TOTAL;;;;'.$datos['total_usd'].';;;');

        return $lineas->implode("\r\n");
    }

    /** Un `;` dentro del nombre de una empresa parte la fila en dos columnas. */
    private static function limpio(string $texto): string
    {
        return str_replace([';', "\r", "\n"], [',', ' ', ' '], $texto);
    }
}
