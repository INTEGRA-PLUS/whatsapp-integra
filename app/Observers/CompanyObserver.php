<?php

namespace App\Observers;

use App\Models\Company;
use App\Support\DefaultWhatsAppMenu;
use Illuminate\Support\Facades\Log;

class CompanyObserver
{
    /**
     * Toda empresa nueva nace con su menú de WhatsApp ya armado (apagado, para
     * que lo revisen antes de encenderlo).
     *
     * Va en un observer y no en el controlador del panel maestro porque ese no
     * es el único sitio donde nace una empresa: también los seeders y cualquier
     * alta que se añada más adelante. Colgarlo del modelo es lo único que
     * garantiza que ninguna empresa se quede sin él.
     */
    public function created(Company $company): void
    {
        try {
            DefaultWhatsAppMenu::createFor($company);
        } catch (\Throwable $e) {
            // Una empresa sin menú es un inconveniente; una empresa que no se
            // pudo crear es un alta caída. El menú no tumba el alta.
            Log::warning('No se pudo crear el menú por defecto de la empresa', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
