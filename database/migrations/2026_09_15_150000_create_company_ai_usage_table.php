<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas conversaciones con IA lleva cada empresa este mes.
 *
 * Una fila por empresa y mes, no una por evento. Un registro por inferencia
 * sería más preciso y crecería a millones de filas para responder una pregunta
 * que se hace una vez al mes: cuánto facturar. Lo que sí guarda es el desglose
 * por tipo, porque la pregunta que viene después de «cuánto» es siempre «en qué»
 * —y sin eso no se puede calibrar qué función merece el coste—.
 *
 * `periodo` es el primer día del mes. Como fecha y no como texto 'YYYY-MM': las
 * consultas del panel son por rango («los últimos seis meses») y sobre texto eso
 * obliga a comparaciones que no usan índice.
 *
 * Esto NO apaga nada al llegar al crédito. Cuenta para facturar el exceso y para
 * avisar. Cortar a mitad de una conversación con un socio no compensa lo que se
 * ahorra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('periodo');

            // Conversaciones distintas que usaron IA en el mes. Es la unidad en
            // la que se vende, así que es la que se cuenta.
            $table->unsignedInteger('conversaciones')->default(0);

            // El desglose por función, para saber en qué se va el crédito.
            $table->unsignedInteger('eventos_menu')->default(0);
            $table->unsignedInteger('eventos_chat')->default(0);
            $table->unsignedInteger('eventos_semaforo')->default(0);
            $table->unsignedInteger('eventos_resumen')->default(0);

            // Coste acumulado en USD. Seis decimales porque un evento cuesta
            // 0,00032: con cuatro, los más baratos se redondean a cero y el mes
            // entero sale gratis.
            $table->decimal('coste_usd', 12, 6)->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_ai_usage');
    }
};
