<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Services\RegistrarLineaEnIntegra;
use Illuminate\Console\Command;

/**
 * Da de alta en Integra una línea que ya existe en el CRM.
 *
 * El alta automática se puso el 10-sep-2026, así que **toda línea conectada
 * antes de esa fecha no existe en el ERP de su empresa**. Y una línea que el ERP
 * no conoce no puede usarla: sigue enviando por la única que tiene, por mucho
 * que en el CRM se elija otra.
 *
 * Es justo lo que le pasó a Transinternet, y era invisible desde los dos lados:
 * en el CRM la línea está activa y elegida, y en Integra sencillamente no está.
 *
 * Crear es idempotente: si ya estaba, Integra lo dice y no se duplica nada.
 */
class RegistrarLinea extends Command
{
    protected $signature = 'integra:registrar-linea {instancia : Id de la instancia del CRM}';

    protected $description = 'Da de alta en el ERP una línea de WhatsApp que ya existe en el CRM';

    public function handle(RegistrarLineaEnIntegra $registrar): int
    {
        $instance = Instance::find($this->argument('instancia'));

        if (! $instance) {
            $this->error('Esa instancia no existe.');

            return self::FAILURE;
        }

        $this->line('  '.$instance->name.' · '.$instance->display_phone_number.' · '.$instance->phone_number_id);

        $resultado = $registrar($instance);

        if (! $resultado['empujada']) {
            $this->error('No se pudo registrar: '.($resultado['aviso'] ?? $resultado['motivo'] ?? 'sin detalle'));

            return self::FAILURE;
        }

        $this->newLine();

        $this->info(($resultado['creada'] ?? false)
            ? 'Dada de alta en Integra. No estaba: por eso el ERP no podía usarla.'
            : 'Ya estaba en Integra. El problema es otro.');

        return self::SUCCESS;
    }
}
