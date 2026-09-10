<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de pantalla de cada usuario.
 *
 * Nace de esconder etapas en el tablero: con doce columnas, un agente de
 * soporte no quiere ver las cuatro de facturación, pero esa decisión es suya y
 * no de la empresa —el siguiente agente quiere justo las otras—. En la sesión
 * se perdería al cerrarla; aquí sigue ahí cuando vuelve a entrar y también
 * desde otro equipo.
 *
 * Una columna JSON y no una tabla porque son ajustes de vista, sueltos y sin
 * relaciones: hoy `etapas_ocultas`, mañana lo que haga falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferencias')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferencias');
        });
    }
};
