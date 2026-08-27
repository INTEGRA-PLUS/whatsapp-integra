<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajustes propios de cada acción de negocio: el servicio y la prioridad con
     * los que se crea el radicado, la URL de pago, la estrategia de reparto del
     * handoff…
     *
     * Van en un JSON y no en columnas porque cada integración que se conecta
     * trae sus propios parámetros: con columnas, conectar "Reportar falla"
     * habría sido una migración, y la siguiente integración otra.
     */
    public function up(): void
    {
        Schema::table('whatsapp_menu_options', function (Blueprint $table) {
            $table->json('config')->nullable()->after('assign_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_menu_options', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
