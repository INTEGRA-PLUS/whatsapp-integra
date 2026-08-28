<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `action_type` nació como ENUM con las tres acciones que ya funcionaban.
     * Al añadir las opciones de negocio (consultar factura, pagar en línea,
     * reportar falla…) MySQL guardaría '' en silencio para cualquier valor
     * fuera del ENUM, así que se pasa a VARCHAR.
     *
     * La tabla ya se creó en entornos donde la migración original corrió con el
     * ENUM; para los nuevos, esta migración se queda en un MODIFY sin efecto.
     *
     * En MySQL se conserva el ALTER crudo para no alterar el tipo exacto en
     * producción; en el resto de drivers (sqlite en las pruebas) se usa el
     * Schema Builder, que sí sabe recrear la tabla.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE whatsapp_menu_options MODIFY action_type VARCHAR(32) NOT NULL');

            return;
        }

        Schema::table('whatsapp_menu_options', function (Blueprint $table) {
            $table->string('action_type', 32)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        DB::statement("UPDATE whatsapp_menu_options SET action_type = 'reply_text' WHERE action_type NOT IN ('reply_text', 'submenu', 'handoff')");

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE whatsapp_menu_options MODIFY action_type ENUM('reply_text', 'submenu', 'handoff') NOT NULL");

            return;
        }

        Schema::table('whatsapp_menu_options', function (Blueprint $table) {
            $table->string('action_type', 32)->nullable(false)->change();
        });
    }
};
