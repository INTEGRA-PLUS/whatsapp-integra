<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El plan contratado por cada empresa, y si se le cobra.
 *
 * Hasta hoy no existía ninguna de las dos cosas: cualquier empresa con un admin
 * podía instalar las cinco extensiones, las de IA incluidas. La tabla de precios
 * era un PDF que el sistema no hacía cumplir.
 *
 * ## Por qué son dos columnas y no una
 *
 * `plan` es **qué puede usar**. `cobro` es **si paga**. Se parecen y no son lo
 * mismo, y mezclarlos es el error que obliga a rehacer esto dentro de seis meses:
 * las once empresas que ya usan el CRM tienen que quedarse con todo encendido
 * *y* sin factura mientras dure la transición. Con una sola columna, "cliente
 * antiguo" acabaría siendo un plan más en el catálogo, y cada función nueva
 * habría que acordarse de añadírsela.
 *
 * Separadas, la transición es un cambio de `cobro` el día que firmen, sin tocar
 * lo que usan ni arriesgarse a apagarle algo a alguien que lleva un año
 * trabajando con ello.
 *
 * ## Con qué nacen las empresas de hoy
 *
 * Plan `inteligente` y cobro `cortesia`. Todo encendido y sin factura, que es
 * exactamente su situación real. Degradarlas con una migración les apagaría
 * funciones que usan hoy sin que nadie se lo haya dicho, y una migración no es
 * el sitio para tomar una decisión comercial.
 *
 * `contactos_contratados` es el tramo de la escalera de precios, **no un límite
 * duro**: nadie deja de atender a un socio porque la cooperativa creció. Sirve
 * para avisar cuándo toca renegociar el tramo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Texto y no enum: cambiar un enum en MySQL reescribe la tabla, y
            // los planes comerciales cambian mucho más que el esquema.
            $table->string('plan', 20)->default('inteligente')->after('active');

            // 'cortesia' | 'prueba' | 'activo' | 'suspendido'.
            $table->string('cobro', 20)->default('cortesia')->after('plan');

            // El mes gratis, y cualquier otra gracia que se conceda desde el
            // panel maestro. Mientras no haya pasado, no se factura aunque el
            // cobro sea 'activo'.
            $table->date('gratis_hasta')->nullable()->after('cobro');

            $table->unsignedInteger('contactos_contratados')->nullable()->after('gratis_hasta');

            // Por qué está en cortesía, quién autorizó el mes gratis, qué se
            // acordó por teléfono. Lo escribe soporte desde el panel maestro.
            //
            // Existe porque estas decisiones se toman en una llamada y se
            // olvidan: dentro de un año nadie recordará por qué esta empresa no
            // paga, y sin un sitio donde escribirlo acaba en un WhatsApp.
            $table->string('nota_de_cobro', 300)->nullable()->after('contactos_contratados');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'plan',
                'cobro',
                'gratis_hasta',
                'contactos_contratados',
                'nota_de_cobro',
            ]);
        });
    }
};
