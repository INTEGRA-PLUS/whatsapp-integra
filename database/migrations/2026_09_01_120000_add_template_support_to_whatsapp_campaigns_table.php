<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->string('template_name')->nullable()->after('message_type');
            $table->string('template_language', 16)->nullable()->after('template_name');
            // Cómo rellenar la plantilla en cada envío: valores de las variables
            // del cuerpo (con tokens {{nombre}}/{{telefono}}) y el encabezado.
            // No son los `components` de Meta: esos se arman en el job, porque el
            // media_id del encabezado caduca y hay que rehacerlo en cada corrida.
            $table->json('template_payload')->nullable()->after('template_language');

            // Una campaña de plantilla no tiene texto libre.
            $table->text('message')->nullable()->change();
        });

        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table) {
            // Enlace al mensaje del chat, para que el hilo muestre lo enviado y
            // los webhooks de entregado/leído tengan dónde caer.
            $table->unsignedBigInteger('message_id')->nullable()->after('wamid');
            $table->foreign('message_id')->references('id')->on('whatsapp_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
            $table->dropColumn('message_id');
        });

        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->dropColumn(['template_name', 'template_language', 'template_payload']);
            $table->text('message')->nullable(false)->change();
        });
    }
};
