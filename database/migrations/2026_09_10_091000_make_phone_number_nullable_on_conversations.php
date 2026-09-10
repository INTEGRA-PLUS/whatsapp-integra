<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una conversación puede no tener teléfono.
 *
 * `phone_number` nació obligatorio porque el único canal era WhatsApp y allí
 * siempre hay un número. Ya no es cierto ni dentro de WhatsApp —desde que Meta
 * deja ocultarlo, el cliente llega como BSUID— y deja de serlo del todo con
 * Messenger e Instagram, donde el identificador es un PSID o un IGSID con
 * ámbito de página o de cuenta, y no existe ningún teléfono que guardar.
 *
 * La identidad de la conversación no se toca: sigue siendo `(instance_id,
 * wa_id)`, que es único y admite cualquiera de esas formas. Lo que se relaja es
 * un campo que era descriptivo y estaba declarado como obligatorio.
 *
 * Nada que rellenar: las filas existentes tienen su número y se quedan como
 * están.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volver atrás sólo es posible si nadie guardó una conversación sin
        // teléfono. Si las hay, revertir esto las rompería, así que se deja el
        // campo como está y se dice por qué.
        if (DB::table('whatsapp_conversations')->whereNull('phone_number')->exists()) {
            return;
        }

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->string('phone_number')->nullable(false)->change();
        });
    }
};
