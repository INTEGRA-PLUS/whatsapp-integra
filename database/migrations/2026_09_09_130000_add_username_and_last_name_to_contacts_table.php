<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La agenda deja de exigir teléfono.
 *
 * Desde que WhatsApp permite esconder el número tras un nombre de usuario, hay
 * clientes de los que Meta no manda ninguno: la ficha no se podía crear porque
 * `phone_number` era obligatorio y único, así que el segundo cliente sin número
 * chocaba con el primero. Ahora el número es opcional —en MySQL un único con
 * NULL admite tantas filas como haga falta— y el nombre de usuario es una
 * segunda forma de identificar al cliente, también única por empresa.
 *
 * El apellido va aparte para poder pintar el formulario como el de WhatsApp
 * (nombre / apellido / usuario / país + teléfono).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('last_name')->nullable()->after('name');
            $table->string('username')->nullable()->after('phone_number');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->change();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->unique(['company_id', 'username']);
        });
    }

    public function down(): void
    {
        // Las fichas sin número no caben en el esquema viejo: se quedarían con
        // el string vacío y chocarían entre ellas al restaurar el único.
        DB::table('contacts')->whereNull('phone_number')->delete();

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'username']);
            $table->dropColumn(['username', 'last_name']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('phone_number')->nullable(false)->change();
        });
    }
};
