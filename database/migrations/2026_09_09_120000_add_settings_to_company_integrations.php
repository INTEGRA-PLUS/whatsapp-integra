<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            // Ajustes propios de la integración que no son credenciales.
            //
            // Nace para el perfil del asistente de IA —nombre, tono,
            // conocimiento de la empresa, límites—, que es lo que hace que el
            // cliente hable con "el asistente de su empresa" y no con el mismo
            // asistente genérico en las cuarenta empresas.
            //
            // Genérica y no `ai_profile` porque `company_integrations` ya
            // guarda filas de cosas muy distintas: la siguiente integración
            // que necesite ajustes sin credenciales cabe aquí sin otra
            // migración. Va al lado de `abilities`, que es la otra columna que
            // describe lo que la fila autoriza en vez de cómo se conecta.
            $table->json('settings')->nullable()->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
