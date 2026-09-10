<?php

namespace App\Console\Commands;

use App\Models\CompanyIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ¿Sigue esta instalación pudiendo leer las credenciales que guardó?
 *
 * `company_integrations.access_token` está cifrado con la `APP_KEY`. Si esa
 * llave cambia, **todas** las integraciones dejan de funcionar a la vez, sin
 * borrarse: la fila sigue ahí, en `status = connected`, y la pantalla enseña el
 * formulario de conectar como si nunca se hubiera conectado nadie. El cliente lo
 * vive como «se me borró la integración» y la vuelve a crear, que es lo único
 * que aparentemente la arregla — hasta el siguiente despliegue.
 *
 * Pasó el 10-sep-2026 y tres veces seguidas con la misma empresa. La causa no
 * estaba en el código: `.env` y `.env.docker` tenían **dos `APP_KEY` distintas**,
 * y el contenedor arrancaba con una u otra según se desplegara con
 * `--env-file .env.docker` o sin él. Con la llave equivocada nadie se enteraba:
 * no hay error en el log salvo un `MAC is invalid` suelto por cada visita.
 *
 * Esto lo convierte en una alerta: si de un día para otro las credenciales
 * dejan de leerse, se dice cuántas y de quién, con el nombre de la causa.
 */
class VigilarCredencialesDeIntegracion extends Command
{
    protected $signature = 'integraciones:credenciales';

    protected $description = 'Avisa si las credenciales guardadas ya no se pueden descifrar con la APP_KEY actual';

    public function handle(): int
    {
        $ilegibles = CompanyIntegration::all()->filter->tokenIlegible();

        if ($ilegibles->isEmpty()) {
            $this->info('Todas las credenciales guardadas se pueden leer.');

            return self::SUCCESS;
        }

        $detalle = $ilegibles
            ->map(fn (CompanyIntegration $i) => $i->company?->name.' ('.$i->key.')')
            ->all();

        $aviso = $ilegibles->count().' credenciales de integración no se pueden descifrar con la APP_KEY '
            .'actual. Eso deja esas integraciones sin funcionar aunque la fila siga en «connected», y el '
            .'cliente lo ve como si se le hubiera borrado. Casi siempre es que el contenedor arrancó con '
            .'otra APP_KEY: comprueba que .env y .env.docker tengan la misma antes de hacer reconectar a '
            .'nadie, porque reconectar con la llave equivocada sólo aplaza el problema.';

        $this->error($aviso);

        foreach ($detalle as $linea) {
            $this->line('  · '.$linea);
        }

        Log::error($aviso, ['integraciones' => $detalle]);

        return self::FAILURE;
    }
}
