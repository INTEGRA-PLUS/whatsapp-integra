<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las campañas pasan a enviarse con plantillas aprobadas.
 *
 * Hasta ahora una campaña era texto libre, y WhatsApp solo acepta texto libre
 * dentro de las 24h siguientes al último mensaje del cliente: justo lo que no
 * ocurre en un envío masivo. Meta contestaba 200, la fila quedaba "enviada" y
 * el rechazo llegaba después por webhook, donde nadie lo veía porque la campaña
 * no dejaba rastro en el chat. Aquí se añade lo que hace falta para enviar por
 * plantilla, personalizar por destinatario y seguir el resultado real de cada
 * envío (entregado / leído / fallido con su motivo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->string('template_name')->nullable()->after('message');
            $table->string('template_language', 16)->nullable()->after('template_name');
            // Instantánea de la definición aprobada: la vista previa y el detalle
            // deben seguir mostrando lo que se envió aunque después se edite o se
            // borre la plantilla en Meta.
            $table->json('template_components')->nullable()->after('template_language');
            // Qué va en cada {{n}}: un texto fijo o un campo del destinatario.
            $table->json('variable_map')->nullable()->after('template_components');
            $table->string('header_media_id')->nullable()->after('variable_map');
            $table->string('header_media_url', 1024)->nullable()->after('header_media_id');
            $table->string('header_filename')->nullable()->after('header_media_url');
            // Meta responde 429/131056 si se le riega; el envío se escalona.
            $table->unsignedSmallInteger('rate_per_minute')->default(60)->after('total_recipients');
            $table->timestamp('paused_at')->nullable()->after('completed_at');
            $table->timestamp('cancelled_at')->nullable()->after('paused_at');
        });

        // El texto libre deja de ser obligatorio: una campaña por plantilla no lo
        // tiene. Y el estado gana "paused", que el enum original no contemplaba.
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->text('message')->nullable()->change();
            $table->string('status', 20)->default('draft')->change();
        });

        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->nullable()->after('campaign_id');
            $table->unsignedBigInteger('conversation_id')->nullable()->after('contact_id');
            // La burbuja que se creó en el chat para este destinatario.
            $table->unsignedBigInteger('message_id')->nullable()->after('conversation_id');
            // Los valores ya resueltos de este destinatario, para poder repetir el
            // envío exactamente igual y para auditar qué se le dijo a quién.
            $table->json('variables')->nullable()->after('name');
            $table->string('error_code', 20)->nullable()->after('error_message');
            $table->text('error_details')->nullable()->after('error_code');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('read_at')->nullable()->after('delivered_at');
            $table->unsignedSmallInteger('attempts')->default(0)->after('read_at');
            // El webhook busca por wamid en cada acuse.
            $table->index('wamid', 'wa_campaign_recipients_wamid_idx');
        });

        // pending | sending | sent | delivered | read | failed | skipped: el enum
        // original solo conocía los tres primeros.
        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });

        if (!Schema::hasColumn('whatsapp_messages', 'campaign_id')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('campaign_id')->nullable()->after('template_id');
                $table->index('campaign_id', 'wa_messages_campaign_idx');
            });
        }

        // Lo enviado hasta hoy era texto libre; que quede dicho en los datos.
        DB::table('whatsapp_campaigns')->whereNull('message_type')->update(['message_type' => 'text']);
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'template_name',
                'template_language',
                'template_components',
                'variable_map',
                'header_media_id',
                'header_media_url',
                'header_filename',
                'rate_per_minute',
                'paused_at',
                'cancelled_at',
            ]);
        });

        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->dropIndex('wa_campaign_recipients_wamid_idx');
            $table->dropColumn([
                'contact_id',
                'conversation_id',
                'message_id',
                'variables',
                'error_code',
                'error_details',
                'delivered_at',
                'read_at',
                'attempts',
            ]);
        });

        if (Schema::hasColumn('whatsapp_messages', 'campaign_id')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->dropIndex('wa_messages_campaign_idx');
                $table->dropColumn('campaign_id');
            });
        }
    }
};
