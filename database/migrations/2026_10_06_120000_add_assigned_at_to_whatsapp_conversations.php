<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se asignó el chat a quien lo tiene.
 *
 * 2026-10-06: el chat se suelta si en 1 h ni el asesor asignado ni un
 * administrador le escriben al cliente (App\Support\QuienAtiende). Sin esta
 * fecha, a quien acababa de tomar el chat con «Atenderla yo» —y aún no había
 * escrito— se le quitaba en cuanto el cliente volvía a escribir.
 *
 * Las asignaciones de antes quedan en null: se miden sólo por lo que escribió
 * el asesor, que es justo lo que destapa las que llevaban días abandonadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn('assigned_at');
        });
    }
};
