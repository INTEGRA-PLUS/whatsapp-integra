<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            // Salud real de la instancia contra Meta, que NO es lo mismo que
            // `active`: esa es una casilla nuestra que alguien marcó una vez.
            //
            // El 2026-09-05 aparecieron cinco empresas cuyo token o número ya
            // no existían del lado de Meta —la más antigua llevaba seis meses
            // así— y las cinco se veían "Activa" en verde. Nadie se enteró
            // hasta que se revisó a mano buscando otra cosa.
            //
            // ok | unreachable | null (nunca revisada)
            $table->string('health_status', 16)->nullable()->after('active');
            $table->timestamp('health_checked_at')->nullable()->after('health_status');
            // Mensaje de Meta del último fallo, para no tener que repetir la
            // consulta a mano cuando alguien pregunte qué pasó.
            $table->string('health_error')->nullable()->after('health_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn(['health_status', 'health_checked_at', 'health_error']);
        });
    }
};
