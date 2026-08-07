<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `template_id` ya existe en los entornos desplegados pero nunca tuvo migración,
 * así que una base recién migrada (tests incluidos) no lo tenía y cualquier
 * inserción de mensajes fallaba. La guarda deja la migración inocua donde la
 * columna ya está creada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_messages', 'template_id')) {
                $table->integer('template_id')->nullable()->after('incoming_company_nit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_messages', 'template_id')) {
                $table->dropColumn('template_id');
            }
        });
    }
};
