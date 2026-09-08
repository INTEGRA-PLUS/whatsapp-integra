<?php

use App\Models\CompanyIntegration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparte los permisos de la IA por empresa.
 *
 * Hasta ahora vivían en el flujo de n8n (`CONFIG.permisos`) e iguales para toda
 * la plataforma: `radicados: true` y `pagos: true` aplicaban a cualquier
 * empresa que encendiera el interruptor. El diseño acordado era otro —IA
 * activable por empresa con permisos separados para lectura, radicados y
 * pagos— y esto lo pone donde debía estar.
 *
 * El reparto no es uniforme a propósito:
 *
 * - Empresa con la IA **encendida**: se le conceden los tres. Hoy ya los tiene
 *   de hecho, y quitárselos en un despliegue sería apagarle funciones en
 *   silencio a alguien que las está usando.
 * - Empresa con la IA **apagada**: sólo lectura. Nunca ha usado la IA, así que
 *   no hay nada que preservar, y encender el interruptor mañana no puede
 *   significar autorizar radicados y cobros sin haberlo decidido.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('company_integrations')
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->whereNull('abilities')
            ->get(['id', 'enabled']);

        foreach ($rows as $row) {
            DB::table('company_integrations')
                ->where('id', $row->id)
                ->update([
                    'abilities' => json_encode($row->enabled
                        ? CompanyIntegration::AI_PERMISSIONS
                        : CompanyIntegration::AI_PERMISSIONS_DEFAULT),
                ]);
        }
    }

    public function down(): void
    {
        // Volver a null es exactamente el estado anterior: sin permisos por
        // empresa, mandan los del flujo.
        DB::table('company_integrations')
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->update(['abilities' => null]);
    }
};
