<?php

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Support\DefaultAiMenusIntegration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Las empresas que ya operaban también estrenan la tarjeta de IA.
     *
     * De las nuevas se encarga CompanyObserver; esto es sólo para ponerse al
     * día con las que existían antes. Se salta a las que ya la tienen, así que
     * correr la migración dos veces no duplica nada ni pisa ajustes.
     *
     * Nace apagada y sin URL, así que ningún cliente de ninguna empresa nota
     * nada hasta que alguien de esa empresa la configure y la encienda.
     */
    public function up(): void
    {
        Company::orderBy('id')->chunk(100, function ($companies) {
            foreach ($companies as $company) {
                DefaultAiMenusIntegration::createFor($company);
            }
        });
    }

    /**
     * Sólo se van las que siguen apagadas y sin conectar. Si alguien ya la
     * configuró, es porque la hizo suya: revertir la migración no es motivo
     * para borrarle la URL de su Ollama y sus permisos.
     */
    public function down(): void
    {
        CompanyIntegration::where('key', CompanyIntegration::KEY_AI_MENUS)
            ->where('enabled', false)
            ->whereNull('base_url')
            ->delete();
    }
};
