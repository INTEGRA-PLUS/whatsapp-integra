<?php

namespace App\Services;

use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Support\IntegrationProvider;
use Illuminate\Support\Facades\Log;

/**
 * Dar de alta en Integra la línea de WhatsApp que se acaba de conectar aquí.
 *
 * Hasta el 10-sep-2026 una línea había que registrarla **a mano en los dos
 * sistemas**. Eso produjo el enredo de Transinternet: dos líneas activas en el
 * CRM, una que enviaba facturas y otra que no, y nadie sabía cuál era cuál
 * porque en Integra sólo existía una de las dos.
 *
 * Es el lado «push» del reparto: el alta de una línea es un hecho que ocurre una
 * vez, así que se empuja. La configuración —qué línea usar, qué plantilla— se
 * sigue preguntando desde Integra, porque eso cambia y copiarlo desincroniza.
 *
 * **Sólo pasa si el complemento de Integra está conectado.** Sin conexión no hay
 * a dónde empujar, y no es un error: es una empresa que todavía no usa Integra.
 */
class RegistrarLineaEnIntegra
{
    /**
     * @return array{empujada: bool, motivo?: string, aviso?: string}
     */
    public function __invoke(Instance $instance): array
    {
        $integracion = CompanyIntegration::where('company_id', $instance->company_id)
            ->whereIn('key', IntegrationProvider::find(IntegrationProvider::INTEGRA)['legacy_keys'] ?? [])
            ->get()
            ->first(fn (CompanyIntegration $i) => $i->isConnected());

        if (! $integracion) {
            return ['empujada' => false, 'motivo' => 'sin_conexion'];
        }

        $cliente = $integracion->client();

        if (! $cliente) {
            return ['empujada' => false, 'motivo' => 'sin_credenciales'];
        }

        $resultado = $cliente->crearInstancia([
            'phone_number_id' => $instance->phone_number_id,
            'waba_id' => $instance->waba_id,
            'nombre' => $instance->name,
            'numero' => $instance->display_phone_number,
        ]);

        if ($resultado['ok']) {
            Log::info('Integra: línea registrada desde el CRM', [
                'company_id' => $instance->company_id,
                'instance_id' => $instance->id,
                'ya_estaba' => ! $resultado['creada'],
            ]);

            return ['empujada' => true];
        }

        // Un fallo aquí no puede tumbar el alta de la línea en el CRM: la línea
        // ya está conectada con Meta y funcionando. Se avisa y se sigue.
        Log::warning('Integra: no se pudo registrar la línea', [
            'company_id' => $instance->company_id,
            'instance_id' => $instance->id,
            'error' => $resultado['error'] ?? null,
        ]);

        return [
            'empujada' => false,
            'motivo' => 'error',
            // Los dos casos que el admin puede arreglar, dichos con lo que hay
            // que hacer y no con el código de error.
            'aviso' => match (true) {
                ($resultado['sin_permiso'] ?? false) => 'La línea quedó conectada, pero no se pudo registrar en Integra: '
                    .'tu conexión con Integra es anterior a esta función. Vuelve a conectarla en Integraciones.',
                ($resultado['sin_endpoint'] ?? false) => 'La línea quedó conectada, pero tu versión de Integra todavía no '
                    .'admite registrar líneas desde aquí. Actualízala y vuelve a intentarlo.',
                default => 'La línea quedó conectada, pero no se pudo registrar en Integra. '
                    .'Revisa la conexión en Integraciones.',
            },
        ];
    }
}
