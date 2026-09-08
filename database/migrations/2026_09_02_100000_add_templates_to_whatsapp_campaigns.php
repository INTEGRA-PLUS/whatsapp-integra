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
 *
 * ---------------------------------------------------------------------------
 * Cada columna se añade sólo si falta.
 *
 * No es celo: hay entornos donde parte de esto ya existe —`template_name` y
 * `template_language` en `whatsapp_campaigns`, `message_id` en los
 * destinatarios— sin que la migración conste como aplicada. Ahí un `add` a
 * secas aborta con "Duplicate column name" y deja el resto sin crear, así que
 * la base se queda a medias y la migración no se puede volver a lanzar.
 * Preguntar antes de añadir es lo que la hace repetible.
 * ---------------------------------------------------------------------------
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $this->unless('whatsapp_campaigns', 'template_name',
                fn () => $table->string('template_name')->nullable()->after('message'));
            $this->unless('whatsapp_campaigns', 'template_language',
                fn () => $table->string('template_language', 16)->nullable()->after('template_name'));
            // Instantánea de la definición aprobada: la vista previa y el detalle
            // deben seguir mostrando lo que se envió aunque después se edite o se
            // borre la plantilla en Meta.
            $this->unless('whatsapp_campaigns', 'template_components',
                fn () => $table->json('template_components')->nullable()->after('template_language'));
            // Qué va en cada {{n}}: un texto fijo o un campo del destinatario.
            $this->unless('whatsapp_campaigns', 'variable_map',
                fn () => $table->json('variable_map')->nullable()->after('template_components'));
            $this->unless('whatsapp_campaigns', 'header_media_id',
                fn () => $table->string('header_media_id')->nullable()->after('variable_map'));
            $this->unless('whatsapp_campaigns', 'header_media_url',
                fn () => $table->string('header_media_url', 1024)->nullable()->after('header_media_id'));
            $this->unless('whatsapp_campaigns', 'header_filename',
                fn () => $table->string('header_filename')->nullable()->after('header_media_url'));
            // Meta responde 429/131056 si se le riega; el envío se escalona.
            $this->unless('whatsapp_campaigns', 'rate_per_minute',
                fn () => $table->unsignedSmallInteger('rate_per_minute')->default(60)->after('total_recipients'));
            $this->unless('whatsapp_campaigns', 'paused_at',
                fn () => $table->timestamp('paused_at')->nullable()->after('completed_at'));
            $this->unless('whatsapp_campaigns', 'cancelled_at',
                fn () => $table->timestamp('cancelled_at')->nullable()->after('paused_at'));
        });

        // El texto libre deja de ser obligatorio: una campaña por plantilla no lo
        // tiene. Y el estado gana "paused", que el enum original no contemplaba.
        // Un `change()` sí se puede repetir: deja la columna como se le pide.
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->text('message')->nullable()->change();
            $table->string('status', 20)->default('draft')->change();
        });

        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table) {
            $this->unless('whatsapp_campaign_recipients', 'contact_id',
                fn () => $table->unsignedBigInteger('contact_id')->nullable()->after('campaign_id'));
            $this->unless('whatsapp_campaign_recipients', 'conversation_id',
                fn () => $table->unsignedBigInteger('conversation_id')->nullable()->after('contact_id'));
            // La burbuja que se creó en el chat para este destinatario.
            $this->unless('whatsapp_campaign_recipients', 'message_id',
                fn () => $table->unsignedBigInteger('message_id')->nullable()->after('conversation_id'));
            // Los valores ya resueltos de este destinatario, para poder repetir el
            // envío exactamente igual y para auditar qué se le dijo a quién.
            $this->unless('whatsapp_campaign_recipients', 'variables',
                fn () => $table->json('variables')->nullable()->after('name'));
            $this->unless('whatsapp_campaign_recipients', 'error_code',
                fn () => $table->string('error_code', 20)->nullable()->after('error_message'));
            $this->unless('whatsapp_campaign_recipients', 'error_details',
                fn () => $table->text('error_details')->nullable()->after('error_code'));
            $this->unless('whatsapp_campaign_recipients', 'delivered_at',
                fn () => $table->timestamp('delivered_at')->nullable()->after('sent_at'));
            $this->unless('whatsapp_campaign_recipients', 'read_at',
                fn () => $table->timestamp('read_at')->nullable()->after('delivered_at'));
            $this->unless('whatsapp_campaign_recipients', 'attempts',
                fn () => $table->unsignedSmallInteger('attempts')->default(0)->after('read_at'));

            // El webhook busca por wamid en cada acuse.
            if (! Schema::hasIndex('whatsapp_campaign_recipients', 'wa_campaign_recipients_wamid_idx')) {
                $table->index('wamid', 'wa_campaign_recipients_wamid_idx');
            }
        });

        // pending | sending | sent | delivered | read | failed | skipped: el enum
        // original solo conocía los tres primeros.
        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });

        if (! Schema::hasColumn('whatsapp_messages', 'campaign_id')) {
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
        $this->dropExisting('whatsapp_campaigns', [
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

        if (Schema::hasIndex('whatsapp_campaign_recipients', 'wa_campaign_recipients_wamid_idx')) {
            Schema::table('whatsapp_campaign_recipients', function (Blueprint $table) {
                $table->dropIndex('wa_campaign_recipients_wamid_idx');
            });
        }

        $this->dropExisting('whatsapp_campaign_recipients', [
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

        if (Schema::hasColumn('whatsapp_messages', 'campaign_id')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->dropIndex('wa_messages_campaign_idx');
                $table->dropColumn('campaign_id');
            });
        }
    }

    /** Ejecuta la definición sólo si la columna todavía no existe. */
    private function unless(string $table, string $column, callable $define): void
    {
        if (! Schema::hasColumn($table, $column)) {
            $define();
        }
    }

    /** Borra de la tabla sólo las columnas que estén. */
    private function dropExisting(string $table, array $columns): void
    {
        $present = array_values(array_filter(
            $columns,
            fn (string $c) => Schema::hasColumn($table, $c)
        ));

        if ($present === []) {
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
    }
};
