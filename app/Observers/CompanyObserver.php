<?php

namespace App\Observers;

use App\Models\Company;
use App\Support\DefaultAiMenusIntegration;
use App\Support\DefaultWhatsAppMenu;
use Illuminate\Support\Facades\Log;

class CompanyObserver
{
    /**
     * Toda empresa nueva nace con su menú de WhatsApp ya armado y con la
     * tarjeta de IA lista para configurar (las dos apagadas, para que las
     * revisen antes de encenderlas).
     *
     * Va en un observer y no en el controlador del panel maestro porque ese no
     * es el único sitio donde nace una empresa: también los seeders y cualquier
     * alta que se añada más adelante. Colgarlo del modelo es lo único que
     * garantiza que ninguna empresa se quede sin ello.
     */
    public function created(Company $company): void
    {
        // Cada siembra en su propio try: si el menú falla, la tarjeta de IA se
        // crea igual. Encadenarlas haría que un fallo en la primera dejara a la
        // empresa sin ninguna de las dos.
        $this->seed($company, 'el menú por defecto', fn () => DefaultWhatsAppMenu::createFor($company));
        $this->seed($company, 'la integración de IA', fn () => DefaultAiMenusIntegration::createFor($company));
    }

    /**
     * Una empresa sin menú o sin la tarjeta de IA es un inconveniente que se
     * arregla a mano; una empresa que no se pudo crear es un alta caída. La
     * siembra no tumba el alta.
     */
    private function seed(Company $company, string $que, callable $siembra): void
    {
        try {
            $siembra();
        } catch (\Throwable $e) {
            Log::warning('No se pudo crear ' . $que . ' de la empresa', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
