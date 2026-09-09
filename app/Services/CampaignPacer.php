<?php

namespace App\Services;

use App\Models\WhatsAppCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reparte los turnos de envío de una campaña contra el reloj del número.
 *
 * El ritmo lo pide la campaña (`rate_per_minute`), pero el límite lo impone
 * Meta por `phone_number_id`. Con un reloj por campaña, tres campañas a 60/min
 * sobre el mismo número eran 180/min reales y ninguna sabía de las otras: el
 * `rate_per_minute` daba una sensación de control que no existía.
 *
 * Aquí cada campaña pide su tramo al reloj compartido de la instancia, lo
 * reserva bajo bloqueo y deja la marca movida para la siguiente. Dos campañas
 * simultáneas sobre el mismo número se turnan en vez de sumarse.
 */
class CampaignPacer
{
    /**
     * Reserva `$count` turnos y devuelve desde cuándo y cada cuánto usarlos.
     *
     * @return array{0: CarbonImmutable, 1: int} [inicio, espaciado en ms]
     */
    public function reserve(int $instanceId, int $count, int $ratePerMinute): array
    {
        $rate = max(1, $ratePerMinute);
        $spacingMs = max(1, (int) round(60000 / $rate));
        $now = CarbonImmutable::now();

        if ($count < 1) {
            return [$now, $spacingMs];
        }

        // Con el reloj por campaña se vuelve al comportamiento anterior: cada
        // una empieza ya y se escalona sola. Es la vía de escape si el reparto
        // compartido diera problemas en producción.
        if (config('whatsapp.campaigns.pacing.scope') !== 'instance') {
            return [$now, $spacingMs];
        }

        // Fuera de la transacción: si la fila ya está, esto no hace nada, y así
        // el bloqueo de abajo siempre encuentra algo que bloquear.
        DB::table('campaign_send_slots')->insertOrIgnore([
            'instance_id' => $instanceId,
            'next_slot_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::transaction(function () use ($instanceId, $count, $spacingMs, $now) {
            $fila = DB::table('campaign_send_slots')
                ->where('instance_id', $instanceId)
                ->lockForUpdate()
                ->first();

            $inicio = $fila && $fila->next_slot_at
                ? CarbonImmutable::parse($fila->next_slot_at)
                : $now;

            // El reloj nunca reparte turnos en el pasado: si el número llevaba
            // rato libre, la campaña empieza ahora y no arrastra el hueco.
            if ($inicio->lessThan($now)) {
                $inicio = $now;
            }

            DB::table('campaign_send_slots')
                ->where('instance_id', $instanceId)
                ->update([
                    'next_slot_at' => $inicio->addMilliseconds($count * $spacingMs),
                    'updated_at' => $now,
                ]);

            return [$inicio, $spacingMs];
        });
    }

    /**
     * Devuelve el número a "libre" si ya no queda nada repartido sobre él.
     *
     * Se llama al cancelar. Una campaña de 12.000 a 60/min reserva 200 minutos
     * de reloj; sin esto, cancelarla dejaría el número ocupado esas tres horas
     * y la siguiente campaña arrancaría cuando ya no había nada que esperar.
     *
     * Sólo mueve el reloj para las campañas que aún no se han repartido: los
     * envíos ya encolados conservan el retraso con el que salieron.
     */
    public function releaseIfIdle(int $instanceId): void
    {
        $ocupado = WhatsAppCampaign::where('instance_id', $instanceId)
            ->whereIn('status', ['queued', 'sending'])
            ->exists();

        if ($ocupado) {
            return;
        }

        DB::table('campaign_send_slots')
            ->where('instance_id', $instanceId)
            ->update(['next_slot_at' => null, 'updated_at' => now()]);
    }
}
