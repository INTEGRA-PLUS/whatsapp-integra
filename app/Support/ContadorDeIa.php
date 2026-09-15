<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyAiUsage;
use Illuminate\Support\Facades\DB;

/**
 * Apunta cada uso de IA de una empresa, para facturar el exceso y para saber en
 * qué se va el crédito.
 *
 * **Nunca bloquea.** No devuelve «no puedes» ni lanza excepciones: apunta y se
 * aparta. Llamarlo desde el camino de un mensaje entrante y que decidiera cosas
 * sería poner una consulta a base de datos entre el socio y su respuesta; y
 * cortar a mitad de una conversación por haber llegado al crédito no compensa lo
 * que se ahorra. El panel maestro es quien mira estos números y decide.
 *
 * Por el mismo motivo **traga sus propios errores**: si apuntar el consumo
 * falla, el cliente recibe su respuesta igual. Un contador roto es un problema
 * de facturación; un bot mudo es un problema del cliente.
 */
class ContadorDeIa
{
    /** Coste medido por evento, en USD. */
    private const COSTE = [
        'semaforo' => 0.00032,
        'menu' => 0.00065,
        'chat' => 0.00066,
        'resumen' => 0.00090,
    ];

    /**
     * Apunta un evento de IA.
     *
     * `$conversacionNueva` marca si esta conversación ya había consumido IA este
     * mes. Es lo que separa «eventos» de «conversaciones»: se vende por
     * conversación, y una de seis turnos no son seis.
     */
    public static function apuntar(
        ?int $companyId,
        string $tipo,
        bool $conversacionNueva = false
    ): void {
        if (! $companyId || ! isset(self::COSTE[$tipo])) {
            return;
        }

        try {
            $periodo = now()->startOfMonth()->toDateString();
            $columna = 'eventos_'.$tipo;

            // Un UPSERT y no leer-modificar-escribir: con varios workers de cola
            // atendiendo la misma empresa, el segundo pisaría la cuenta del
            // primero y el consumo saldría siempre por debajo del real.
            DB::table('company_ai_usage')->upsert(
                [[
                    'company_id' => $companyId,
                    'periodo' => $periodo,
                    'conversaciones' => $conversacionNueva ? 1 : 0,
                    $columna => 1,
                    'coste_usd' => self::COSTE[$tipo],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['company_id', 'periodo'],
                [
                    'conversaciones' => DB::raw('conversaciones + '.($conversacionNueva ? 1 : 0)),
                    $columna => DB::raw("{$columna} + 1"),
                    'coste_usd' => DB::raw('coste_usd + '.self::COSTE[$tipo]),
                    'updated_at' => DB::raw("'".now()->toDateTimeString()."'"),
                ]
            );
        } catch (\Throwable $e) {
            // A propósito en silencio: ver arriba.
        }
    }

    /** Lo consumido este mes por una empresa. */
    public static function delMes(int $companyId): CompanyAiUsage
    {
        return CompanyAiUsage::firstOrNew([
            'company_id' => $companyId,
            'periodo' => now()->startOfMonth()->toDateString(),
        ]);
    }

    /**
     * Cómo va la empresa contra su crédito.
     *
     * @return array{usadas:int, incluidas:int, exceso:int, porcentaje:int, coste_usd:float}
     */
    public static function estado(Company $company): array
    {
        $uso = self::delMes($company->id);
        $incluidas = PlanDeLaEmpresa::de($company)->creditoIa();
        $usadas = (int) $uso->conversaciones;

        return [
            'usadas' => $usadas,
            'incluidas' => $incluidas,
            'exceso' => max(0, $usadas - $incluidas),
            'porcentaje' => $incluidas > 0 ? (int) round($usadas / $incluidas * 100) : 0,
            'coste_usd' => round((float) $uso->coste_usd, 4),
        ];
    }
}
