<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE whatsapp_menu_options MODIFY action_type VARCHAR(32) NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE whatsapp_menu_options SET action_type = 'reply_text' WHERE action_type NOT IN ('reply_text', 'submenu', 'handoff')");
        DB::statement("ALTER TABLE whatsapp_menu_options MODIFY action_type ENUM('reply_text', 'submenu', 'handoff') NOT NULL");
    }
};
