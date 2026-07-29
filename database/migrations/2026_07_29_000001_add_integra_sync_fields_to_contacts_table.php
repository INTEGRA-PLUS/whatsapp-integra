<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // 'integra_contactos' cuando el contacto se creó desde la sincronización;
            // null para los contactos manuales / creados desde WhatsApp.
            $table->string('source')->nullable()->after('metadata');
            $table->string('identificacion')->nullable()->after('source');
            // Id del contacto en Integra, para upsert estable en syncs incrementales.
            $table->string('external_id')->nullable()->after('identificacion');

            $table->index(['company_id', 'external_id'], 'contacts_company_external_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_company_external_id_idx');
            $table->dropColumn(['source', 'identificacion', 'external_id']);
        });
    }
};
