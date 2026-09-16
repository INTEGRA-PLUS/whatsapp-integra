<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `contactos_contratados` deja de existir: el plan ya dice el tamaño.
 *
 * Era el tramo de la escalera de precios —500, 2.000, 5.000, 15.000, 30.000—
 * que decidía cuánto pagaba una empresa y cuánto crédito de IA tenía. Esa
 * escalera se retiró el 15-sep-2026 al pasar a tres planes de precio fijo: ahora
 * el plan de CRM incluye sus contactos, sus agentes y sus líneas, y el crédito
 * sale del plan.
 *
 * Desde entonces la columna era un número que alguien podía escribir en el panel
 * y que no cambiaba nada. Eso es peor que no tenerla: quien la viera creería que
 * sirve para algo, y el día que un cliente reclamara por qué su «límite» no se
 * respeta, la respuesta sería que ese campo no lo lee nadie.
 *
 * ## Por qué se borra en vez de dejarla ahí
 *
 * Porque `PlanDeLaEmpresa` es el único sitio por donde pasan las preguntas de
 * plan, y dejar una columna que parece responder una de ellas invita a que el
 * próximo cambio la lea en vez de preguntar. Una columna muerta con nombre
 * evidente es una trampa.
 *
 * Lo que sí se conserva es el dato equivalente: cuántos contactos tiene de
 * verdad se cuenta de `contacts` —cada persona que escribe queda registrada— y
 * cuántos incluye su plan sale de `config/planes.php`. La diferencia entre los
 * dos es lo que dice cuándo toca hablar de subir, y eso no se perdió.
 *
 * `down()` la devuelve vacía: los tramos de cada empresa ya no se pueden
 * reconstruir, y rellenarla con un número inventado sería peor que dejarla nula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('contactos_contratados');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('contactos_contratados')->nullable()->after('gratis_hasta');
        });
    }
};
