<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué canal habla cada línea: WhatsApp, Messenger o Instagram.
 *
 * Va en columna propia y no en `type`, que ya existe pero significa otra cosa:
 * `type` es el **proveedor** —`meta`, `vibio`, `other`— y responde a «con qué
 * API hablamos». El canal responde a «por dónde escribe el cliente final», y
 * son preguntas distintas: Messenger e Instagram también son Meta.
 *
 * Se rellena todo como `whatsapp` porque hoy no hay otra cosa, y el valor por
 * defecto mantiene a salvo cualquier código que cree instancias sin decirlo.
 *
 * Guardada por `hasColumn` como el resto de migraciones de este repo, después
 * de que una sin guardar tumbara producción siete minutos (9-sep-2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('instances', 'channel')) {
            return;
        }

        Schema::table('instances', function (Blueprint $table) {
            $table->string('channel', 20)->default('whatsapp')->after('type');
        });

        // Explícito y no confiando en el DEFAULT: las filas que ya existían se
        // crearon antes de que la columna existiera, y quiero que digan
        // «whatsapp» porque lo son, no porque nadie las tocó.
        DB::table('instances')->update(['channel' => 'whatsapp']);

        Schema::table('instances', function (Blueprint $table) {
            // Se va a filtrar por empresa y canal a la vez en cuanto haya más de
            // uno: la bandeja de un cliente con los tres canales pide siempre
            // los dos campos juntos.
            $table->index(['company_id', 'channel'], 'instances_company_channel_idx');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropIndex('instances_company_channel_idx');
            $table->dropColumn('channel');
        });
    }
};
