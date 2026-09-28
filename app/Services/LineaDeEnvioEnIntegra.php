<?php

namespace App\Services;

use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Support\IntegrationProvider;
use Illuminate\Support\Facades\Log;

/**
 * Dejar en Integra una línea como la que envía facturas y recibos.
 *
 * Integra envía con el `phone_number_id` de su única instancia activa, y el CRM
 * sólo acepta el de una instancia suya que exista y esté activa. Si las dos
 * cosas no coinciden, cada factura recibe «Instancia no válida o token ausente»
 * y en el CRM no queda ni rastro de que lo intentó.
 *
 * Lo que la deja coincidiendo es `PUT /whatsapp/linea-envio` de Integra: crea la
 * línea allá si falta y la activa apagando las demás. Se usa en tres sitios:
 * al elegir la línea en Integraciones, al conectar Integra y con el botón
 * «Sincronizar con Integra» de la tarjeta de la instancia. Nació de Nova
 * Partners (24-sep-2026): conectó el número cuatro minutos ANTES de conectar
 * Integra, el alta automática no tuvo a dónde ir, y el software facturaba contra
 * una línea que el CRM no conocía.
 *
 * Nunca lanza: la línea ya funciona en el CRM, y un Integra caído no puede
 * tumbar lo que la haya llamado. Devuelve la frase que hay que enseñar.
 */
class LineaDeEnvioEnIntegra
{
    /**
     * @return array{ok: bool, sin_conexion?: bool, cambiada?: bool, mensaje: string}
     */
    public function __invoke(Instance $linea): array
    {
        $integracion = CompanyIntegration::where('company_id', $linea->company_id)
            ->whereIn('key', IntegrationProvider::find(IntegrationProvider::INTEGRA)['legacy_keys'] ?? [])
            ->get()
            ->first(fn (CompanyIntegration $i) => $i->isConnected());

        $cliente = $integracion?->client();

        if (! $cliente) {
            return [
                'ok' => false,
                'sin_conexion' => true,
                'mensaje' => 'Integra no está conectado. Conéctalo en Integraciones.',
            ];
        }

        $res = $cliente->usarLineaParaEnvios([
            'phone_number_id' => $linea->phone_number_id,
            'waba_id' => $linea->waba_id,
            'nombre' => $linea->name,
            'numero' => $linea->display_phone_number,
        ]);

        if ($res['ok']) {
            Log::info('Integra: línea de envío sincronizada desde el CRM', [
                'company_id' => $linea->company_id,
                'instance_id' => $linea->id,
                'cambiada' => $res['cambiada'] ?? null,
                'anterior' => $res['anterior'] ?? null,
            ]);

            return [
                'ok' => true,
                'cambiada' => (bool) ($res['cambiada'] ?? false),
                'mensaje' => ($res['cambiada'] ?? false)
                    ? 'Integra ya quedó configurado para enviar por esta línea.'
                    : 'Integra ya enviaba por esta línea: está sincronizada.',
            ];
        }

        Log::warning('Integra: no se pudo sincronizar la línea de envío', [
            'company_id' => $linea->company_id,
            'instance_id' => $linea->id,
            'error' => $res['error'] ?? null,
        ]);

        return [
            'ok' => false,
            'mensaje' => match (true) {
                $res['sin_permiso'] ?? false => 'No se pudo sincronizar con Integra: tu conexión es anterior a esta función. '
                    .'Vuelve a conectarla en Integraciones.',
                $res['sin_endpoint'] ?? false => 'Tu versión de Integra todavía no permite sincronizar la línea desde aquí: '
                    .'actualízala o activa esta línea allá a mano.',
                default => 'No se pudo sincronizar con Integra; revisa la conexión en Integraciones.',
            },
        ];
    }
}
