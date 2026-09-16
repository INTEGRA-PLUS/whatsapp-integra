<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de suscripción: qué periodo tiene pagado cada empresa.
 *
 * Hasta hoy `cobro` era un estado escrito a mano en el panel —`activo`,
 * `suspendido`— y nada más. No había periodo, ni fecha de renovación, ni
 * historial. Sabíamos a quién y cuánto cobrar, pero no si había pagado, ni
 * hasta cuándo, ni cuánto se le cobró la última vez.
 *
 * Hace falta con pasarela y sin ella. IntegraPay, cuando llegue, escribirá aquí:
 * lo que cambia es quién crea la fila, no qué significa.
 *
 * ## Por qué cada cobro guarda una copia del precio
 *
 * `plan`, `ia` e `importe_usd` se copian en la fila en vez de calcularse. Los
 * precios cambian —hoy mismo pasaron de quince a seis— y un recibo tiene que
 * decir lo que se cobró, no lo que costaría hoy. Calcularlo al vuelo hace que el
 * histórico se reescriba solo cada vez que se toca el catálogo, que es la forma
 * más rápida de perder una discusión con un cliente.
 *
 * ## La referencia externa es lo que evita cobrar dos veces
 *
 * `referencia` es el identificador del pago en la pasarela, y es único. Es el
 * mismo patrón que `whatsapp_messages.wamid`: la pasarela puede reintentar el
 * webhook —todas lo hacen— y sin unicidad el segundo intento alargaría la
 * suscripción otro periodo gratis.
 *
 * ## Lo que este registro NO hace
 *
 * No apaga nada al vencer. Una suscripción vencida es un aviso y una
 * conversación, no un corte: apagarle el WhatsApp a una cooperativa un día de
 * recaudo por una factura pendiente es la forma más cara que existe de cobrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Cada cuánto se le cobra. Va en la empresa y no en cada cobro
            // porque es lo que se negoció con ella, no una característica del
            // pago suelto.
            $table->string('ciclo', 20)->default('mensual')->after('cobro');

            // Hasta cuándo tiene pagado. Es el dato operativo: lo demás
            // —historial, importes— es para mirar atrás, y esto es para saber
            // qué pasa hoy.
            $table->date('suscripcion_hasta')->nullable()->after('ciclo');
        });

        Schema::create('suscripcion_cobros', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Copia de lo que se cobró, no referencia a lo que cuesta hoy.
            $table->string('plan', 20);
            $table->string('ia', 20);
            $table->string('ciclo', 20);
            $table->unsignedInteger('importe_usd');

            $table->date('periodo_desde');
            $table->date('periodo_hasta');

            // `pendiente` es un cobro emitido y no pagado; `pagado` lo alarga la
            // suscripción; `anulado` es el que se creó por error. No hay
            // `fallido` aparte: para el negocio es lo mismo que pendiente, y un
            // estado más es un estado que alguien tiene que interpretar.
            $table->string('estado', 20)->default('pendiente');
            $table->timestamp('pagado_at')->nullable();

            // El identificador del pago en la pasarela. Único y nullable: nulo
            // mientras el cobro sea nuestro —un alta a mano desde el panel— y
            // con valor cuando lo confirme IntegraPay.
            $table->string('referencia', 120)->nullable()->unique();

            // Por qué se creó, quién lo autorizó, qué se acordó por teléfono.
            // Existe por lo mismo que `nota_de_cobro`: estas decisiones se toman
            // en una llamada y dentro de un año nadie recuerda por qué.
            $table->string('nota', 300)->nullable();

            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // La consulta de siempre: el historial de una empresa, lo último
            // primero.
            $table->index(['company_id', 'periodo_hasta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suscripcion_cobros');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['ciclo', 'suscripcion_hasta']);
        });
    }
};
