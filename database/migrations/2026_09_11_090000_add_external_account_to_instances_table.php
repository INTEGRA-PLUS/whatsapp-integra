<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La identidad de una línea que no es de WhatsApp, y cuándo caduca su token.
 *
 * `instances` se modeló alrededor de `phone_number_id`, que es el identificador
 * que da Meta a un número de WhatsApp. Una cuenta profesional de Instagram no
 * tiene ninguno: tiene un IGSID, con ámbito de cuenta. Meter ese IGSID en la
 * columna del teléfono habría funcionado hoy y habría sido una mentira
 * permanente —el índice único `(company_id, phone_number_id)`, los diagnósticos
 * y media docena de pantallas dicen «número»—, así que va en columna propia.
 *
 * `token_expires_at` existe porque los tokens no caducan igual según el canal:
 * el de WhatsApp que usamos es de usuario del sistema y no expira, mientras que
 * el de Instagram dura **60 días** y hay que renovarlo antes. Sin saber cuándo
 * vence, los clientes se caerían solos cada dos meses sin que nadie se entere.
 *
 * Guardada por `hasColumn` como el resto de migraciones de este repo, después
 * de que una sin guardar tumbara producción siete minutos (9-sep-2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('instances', 'external_account_id')) {
            return;
        }

        Schema::table('instances', function (Blueprint $table) {
            $table->string('external_account_id')->nullable()->after('waba_id');
            $table->timestamp('token_expires_at')->nullable()->after('access_token');

            // Una misma cuenta de Instagram no puede colgar de dos empresas: si
            // pasara, los mensajes de un cliente final aparecerían en dos
            // bandejas ajenas entre sí. El índice va sin `company_id` a
            // propósito, porque es justo eso lo que hay que impedir.
            //
            // Las filas de WhatsApp lo dejan en NULL y MySQL admite tantos NULL
            // como haga falta en un índice único, así que no les afecta.
            $table->unique(['channel', 'external_account_id'], 'instances_channel_external_idx');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropUnique('instances_channel_external_idx');
            $table->dropColumn(['external_account_id', 'token_expires_at']);
        });
    }
};
