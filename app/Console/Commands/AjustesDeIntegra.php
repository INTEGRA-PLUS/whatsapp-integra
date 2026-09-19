<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Integra;
use Illuminate\Console\Command;

/**
 * Qué tiene configurado el ERP de una empresa, dicho por el propio ERP.
 *
 * Esos ajustes viven en Integra y no aquí —a propósito: dos copias de la misma
 * configuración acaban discrepando— pero eso los volvía invisibles desde la
 * consola. Para saber por qué número estaba enviando el ERP de Transinternet
 * había que abrir su panel, y su panel es suyo.
 *
 * Es de sólo lectura. Entra con el token que esa empresa ya nos dio, así que no
 * ve nada que no viera su propio admin.
 */
class AjustesDeIntegra extends Command
{
    protected $signature = 'integra:ajustes {empresa : Id de la empresa}';

    protected $description = 'Enseña los ajustes de envío que el ERP tiene guardados';

    public function handle(): int
    {
        $company = Company::find($this->argument('empresa'));

        if (! $company) {
            $this->error('Esa empresa no existe.');

            return self::FAILURE;
        }

        $cliente = Integra::for($company->id);

        if (! $cliente) {
            $this->error($company->name.' no tiene Integra conectado.');

            return self::FAILURE;
        }

        $res = $cliente->ajustesDeEnvio();

        if (! ($res['ok'] ?? false)) {
            $this->error('Integra no respondió: '.($res['error'] ?? 'sin detalle'));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  <options=bold>'.$company->name.'</> · '.$cliente->baseUrl());
        $this->newLine();
        $this->line(json_encode($res['datos'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->newLine();

        // La línea que el CRM dice que hay que usar, al lado, para poder
        // cotejarlas de un vistazo: es justo la comparación que hubo que hacer
        // a mano para encontrar lo de Transinternet.
        $elegida = $company->instanciaDelErp();

        if ($elegida) {
            $this->line('  El CRM dice que envíe por: <fg=cyan>'.$elegida->name.'</> ('.$elegida->phone_number_id.')'
                .($company->tieneLineaDelErpElegida() ? ' — elegida a mano' : ' — por defecto'));
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
