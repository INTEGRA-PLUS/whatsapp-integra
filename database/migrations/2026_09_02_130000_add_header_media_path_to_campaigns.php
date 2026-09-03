<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde vive nuestra copia del archivo del encabezado.
 *
 * El media_id de Meta caduca a los 30 días, así que una campaña recurrente
 * dejaría de salir al mes de crearse, con un "Format mismatch" por cada
 * destinatario. Guardando el archivo en nuestro bucket se puede volver a subir
 * en cada corrida y el id nunca llega a caducar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->string('header_media_path', 1024)->nullable()->after('header_media_url');
            $table->string('header_media_mime', 128)->nullable()->after('header_media_path');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->dropColumn(['header_media_path', 'header_media_mime']);
        });
    }
};
