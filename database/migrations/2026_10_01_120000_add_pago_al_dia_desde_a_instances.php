<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se dio por bueno el pago de Meta por última vez.
 *
 * Meta reintenta los envíos durante días y suelta los fallos de golpe: el
 * 131042 de una factura que salió el lunes, con la tarjeta todavía sin poner,
 * puede llegar el miércoles, cuando el cliente ya la puso y la alerta se apagó.
 * Sin esta marca ese fallo viejo volvía a encender la alerta roja y a mandar el
 * correo a los admins por un problema que ya estaba arreglado. Con ella, sólo
 * un fallo de un mensaje enviado DESPUÉS de esa hora cuenta como noticia.
 *
 * Una columna y no `instances.meta`: se lee en el observer de cada mensaje
 * fallido, y `meta` es un cajón compartido que no se toca sin sus helpers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->timestamp('pago_al_dia_desde')->nullable()->after('enlace_de_pago');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn('pago_al_dia_desde');
        });
    }
};
