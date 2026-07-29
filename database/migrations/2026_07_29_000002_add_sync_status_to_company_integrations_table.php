<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            // Se manda como `actualizado_desde` en el siguiente sync de contactos
            // (sincronización incremental).
            $table->timestamp('last_synced_at')->nullable()->after('connected_at');
            // Progreso de la corrida de sync más reciente: { state, page, total_pages,
            // processed, created, matched, error, started_at, finished_at }.
            $table->json('sync_status')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn(['last_synced_at', 'sync_status']);
        });
    }
};
