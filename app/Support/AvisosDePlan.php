<?php

namespace App\Support;

use App\Models\Company;

/**
 * Qué empresas hay que mirar hoy, y por qué.
 *
 * El sistema de planes sabía desde el principio quién se había pasado y a quién
 * se le acababa el crédito, pero había que **entrar a mirarlo**. Un aviso que
 * exige que alguien se acuerde de abrir una pestaña no es un aviso.
 *
 * ## Los tres motivos, y por qué son distintos
 *
 * - **`plan_corto`** — tiene más agentes, contactos o líneas de los que incluye
 *   su plan. Es una conversación comercial: se le llama y se le sube. No se
 *   corta nada mientras tanto, nadie deja de atender a un cliente porque la
 *   empresa creció.
 * - **`credito_agotado`** — se pasó de las conversaciones con IA incluidas. No
 *   se corta tampoco: se factura el exceso, y para eso hay que saberlo.
 * - **`ia_sin_usar`** — contrató el complemento y lleva el mes sin gastar nada.
 *   Es el que más caro sale callado: un cliente que paga por algo que no usa es
 *   un cliente que se va a dar de baja, y llamarle a tiempo lo evita.
 *
 * ## Lo que este objeto NO hace
 *
 * No manda nada ni cambia ningún estado: sólo mira y devuelve. Quien avisa es el
 * comando, y quien decide qué hacer es una persona.
 */
class AvisosDePlan
{
    /**
     * Cuánto del crédito de IA hay que haber gastado para no considerarlo «sin
     * usar». Por debajo de esto, el cliente paga y no lo aprovecha.
     */
    private const UMBRAL_SIN_USAR = 0.05;

    /**
     * @return list<array{
     *     company: Company,
     *     motivo: string,
     *     titulo: string,
     *     cuerpo: string,
     *     firma: string
     * }>
     */
    public static function calcular(): array
    {
        $avisos = [];

        foreach (Company::query()->where('interna', false)->orderBy('name')->get() as $company) {
            $plan = PlanDeLaEmpresa::de($company);

            foreach (self::deLaEmpresa($company, $plan) as $aviso) {
                $avisos[] = $aviso + ['company' => $company];
            }
        }

        return $avisos;
    }

    /** @return list<array{motivo: string, titulo: string, cuerpo: string, firma: string}> */
    private static function deLaEmpresa(Company $company, PlanDeLaEmpresa $plan): array
    {
        $avisos = [];

        // ── Se pasó de lo que incluye su plan ───────────────────────────────
        if ($pasados = $plan->sePasoDe()) {
            $detalle = [];

            foreach ($pasados as $que) {
                $detalle[] = match ($que) {
                    'contactos' => number_format($plan->contactosReales()).' contactos de '.number_format($plan->contactosIncluidos()),
                    'agentes' => $plan->agentesReales().' agentes de '.$plan->agentesIncluidos(),
                    'lineas' => $plan->lineasReales().' líneas de '.$plan->lineasIncluidas(),
                    default => $que,
                };
            }

            $sugerido = $plan->planSugerido();

            $avisos[] = [
                'motivo' => 'plan_corto',
                'titulo' => $company->name.' se pasó de su plan',
                'cuerpo' => 'Tiene '.implode(' y ', $detalle).', y su plan es '.$plan->nombre().'. '
                    .($sugerido
                        ? 'Le correspondería '.config("planes.crm.{$sugerido}.nombre").'.'
                        : 'Se sale del catálogo: hay que cotizarlo a mano.')
                    .' No se le ha cortado nada.',
                // La firma lleva EN QUÉ se pasó, no sólo que se pasó: si primero
                // se pasa de agentes y un mes después también de contactos, son
                // dos conversaciones distintas y las dos hay que tenerlas.
                'firma' => 'plan_corto:'.implode(',', $pasados).':'.$plan->slug(),
            ];
        }

        if (! $plan->tieneIa()) {
            return $avisos;
        }

        $uso = ContadorDeIa::estado($company);
        $incluidas = max(1, (int) ($uso['incluidas'] ?? 0));
        $usadas = (int) ($uso['usadas'] ?? 0);

        // ── Se pasó del crédito de IA ───────────────────────────────────────
        if (($uso['exceso'] ?? 0) > 0) {
            $avisos[] = [
                'motivo' => 'credito_agotado',
                'titulo' => $company->name.' se pasó del crédito de IA',
                'cuerpo' => 'Lleva '.number_format($usadas).' conversaciones con IA este mes, de '
                    .number_format($incluidas).' incluidas. El exceso se factura; no se ha cortado nada.',
                // El mes entra en la firma: es un aviso que tiene que volver a
                // salir el mes que viene si vuelve a pasarse.
                'firma' => 'credito_agotado:'.now()->format('Y-m'),
            ];

            return $avisos;
        }

        // ── Paga la IA y no la usa ──────────────────────────────────────────
        //
        // Sólo a partir del día 10: en los primeros días del mes casi nadie ha
        // gastado nada todavía, y avisar entonces es ruido que enseña a ignorar
        // los avisos.
        if (now()->day >= 10 && $usadas / $incluidas < self::UMBRAL_SIN_USAR) {
            $avisos[] = [
                'motivo' => 'ia_sin_usar',
                'titulo' => $company->name.' paga IA y no la usa',
                'cuerpo' => 'Tiene contratado '.$plan->nombreIa().' y lleva '.number_format($usadas)
                    .' conversaciones este mes. Conviene llamar antes de que se pregunte para qué paga.',
                'firma' => 'ia_sin_usar:'.now()->format('Y-m'),
            ];
        }

        return $avisos;
    }

    /**
     * ¿Ya se avisó de esto?
     *
     * La firma es lo que distingue «lo mismo otra vez» de «algo nuevo». Sin
     * ella, el aviso de que Megastore se pasó de agentes saldría todos los días
     * hasta que alguien lo resolviera, y una campana que repite es una campana
     * que se ignora — y con ella se ignoran los avisos que sí son nuevos.
     */
    public static function yaAvisado(Company $company, string $firma): bool
    {
        return in_array($firma, (array) data_get($company->settings, 'avisos_de_plan', []), true);
    }

    /**
     * Deja constancia de que se avisó.
     *
     * Merge y no asignación: `settings` es un cajón compartido con otras
     * funciones, y escribirlo entero borraría lo que guarde cualquier otra.
     * Es el mismo error que ya costó una vez con `instances.meta`.
     *
     * Se guardan las últimas 20 firmas: ni crece sin fin ni se olvida lo de
     * ayer.
     */
    public static function apuntar(Company $company, string $firma): void
    {
        $settings = (array) $company->settings;
        $firmas = (array) ($settings['avisos_de_plan'] ?? []);

        $firmas[] = $firma;
        $settings['avisos_de_plan'] = array_slice(array_unique($firmas), -20);

        $company->update(['settings' => $settings]);
    }
}
