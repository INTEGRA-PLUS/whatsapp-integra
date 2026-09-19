<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una respuesta rápida puede ser texto o puede mandar la factura del cliente.
 *
 * Hasta ahora `message` era todo lo que una respuesta rápida sabía hacer:
 * pegar un texto. Mandar la última factura —que es la petición que más llega
 * por WhatsApp— obligaba al asesor a buscar el PDF en el hilo, descargarlo y
 * volverlo a subir, o a pedírselo a alguien de facturación.
 *
 * `tipo` se queda en `texto` para todas las que ya existen: el valor por defecto
 * es justo lo que hacían.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_replies', function (Blueprint $table) {
            $table->string('tipo', 20)->default('texto')->after('shortcut');

            // La de factura no lleva texto —lo pone la plantilla— y hasta ahora
            // la columna era obligatoria. Guardar un texto vacío para cumplir el
            // esquema sería guardar una mentira: no es que el mensaje esté en
            // blanco, es que esta respuesta no manda un mensaje.
            $table->text('message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('quick_replies', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });

        // `message` se deja como está: volver a ponerla obligatoria reventaría
        // con las respuestas de factura que ya existan.
    }
};
