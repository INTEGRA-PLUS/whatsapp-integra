<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo habló por última vez el ERP por esta línea, y con qué credencial.
 *
 * Integra 2.0 y el CRM son dos bases de datos distintas, cada una con su tabla
 * `instances`, unidas por una sola cadena de texto: el `phone_number_id`. Hasta
 * ahora **no había ninguna pantalla** donde ver si esa unión estaba viva. Para
 * saber si un ERP estaba enviando por una línea había que contar mensajes con
 * `incoming_invoice_id` en la base de datos (9-sep-2026).
 *
 * `api_token_last_used_at` no servía: sólo lo escribe el camino del token
 * nuevo, y hoy las 51 instancias siguen autenticándose con el `phone_number_id`
 * heredado, así que estaba a null en todas.
 *
 * Se marca en cada llamada, que es O(1), en vez de calcularlo contando el
 * millón de mensajes cada vez que alguien abre Integraciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->timestamp('api_last_seen_at')->nullable()->after('api_token_last_used_at');
            // 'token' o 'phone_number_id': saber con cuál entra es lo que dice
            // si ese cliente ya se puede migrar al token de verdad.
            $table->string('api_last_seen_via', 20)->nullable()->after('api_last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn(['api_last_seen_at', 'api_last_seen_via']);
        });
    }
};
