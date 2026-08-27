<?php

use App\Models\Company;
use App\Support\DefaultWhatsAppMenu;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Las empresas que ya operaban también estrenan el menú por defecto.
     *
     * De las nuevas se encarga CompanyObserver; esto es sólo para ponerse al
     * día con las que existían antes. Se salta a las que ya tienen menús
     * propios: quien ya configuró los suyos no necesita uno más, y correr la
     * migración dos veces no duplica nada.
     *
     * Nace apagado, así que ningún cliente de ninguna empresa nota nada hasta
     * que alguien de esa empresa lo revise y lo encienda.
     */
    public function up(): void
    {
        Company::orderBy('id')->chunk(100, function ($companies) {
            foreach ($companies as $company) {
                DefaultWhatsAppMenu::createFor($company);
            }
        });
    }

    /**
     * Sólo se van los que siguen intactos y apagados. Si alguien ya lo encendió
     * o le cambió el nombre, es porque lo hizo suyo: revertir la migración no
     * es motivo para borrarle una configuración que le costó tiempo.
     */
    public function down(): void
    {
        \App\Models\WhatsAppMenu::where('name', DefaultWhatsAppMenu::NAME)
            ->where('active', false)
            ->whereNull('instance_id')
            ->each(fn ($menu) => $menu->delete());
    }
};
