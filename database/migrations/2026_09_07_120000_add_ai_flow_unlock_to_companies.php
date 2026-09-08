<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El desbloqueo del flujo de IA, por empresa.
 *
 * La IA no se enciende sin más desde el panel: hay que escribir un secreto que
 * sólo tiene el equipo. Guardar cuándo y quién lo hizo —y no un simple booleano—
 * es lo que permite auditar después quién dejó a la IA hablando con clientes.
 *
 * Va en `companies` y no en `company_integrations` porque el desbloqueo no es de
 * una integración concreta: abre el apartado entero, y dentro conviven la IA de
 * menús y la de chats, que son dos procesos distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('ai_flow_unlocked_at')->nullable()->after('settings');
            $table->foreignId('ai_flow_unlocked_by')->nullable()->after('ai_flow_unlocked_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_flow_unlocked_by');
            $table->dropColumn('ai_flow_unlocked_at');
        });
    }
};
