<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuándo llegó el último lote de Meta.
     *
     * Había `first_chunk_at` pero no su pareja, y sin ella no se puede
     * distinguir una importación que sigue viva de una que se quedó muda: las
     * dos se ven igual desde fuera.
     *
     * No vale mirar `updated_at`: el propio vigilante escribe en la fila cuando
     * avisa de que algo lleva horas parado, y eso reiniciaría la cuenta del
     * silencio justo en el caso que hay que detectar.
     */
    public function up(): void
    {
        Schema::table('coexistence_syncs', function (Blueprint $table) {
            $table->timestamp('last_chunk_at')->nullable()->after('first_chunk_at');
        });

        // Las importaciones que ya existen no tienen el dato. Se les pone la
        // fecha del primer lote, que es lo más tarde que se sabe con certeza
        // que Meta habló: peca de conservador —las da por más silenciosas de lo
        // que fueron— y eso es lo correcto para lo que se va a decidir con él.
        Schema::getConnection()
            ->table('coexistence_syncs')
            ->whereNull('last_chunk_at')
            ->update(['last_chunk_at' => Schema::getConnection()->raw('first_chunk_at')]);
    }

    public function down(): void
    {
        Schema::table('coexistence_syncs', function (Blueprint $table) {
            $table->dropColumn('last_chunk_at');
        });
    }
};
