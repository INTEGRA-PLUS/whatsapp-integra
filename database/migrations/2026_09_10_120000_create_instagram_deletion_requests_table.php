<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las peticiones de borrado de datos que llegan por Instagram.
 *
 * Meta obliga a publicar una URL de eliminación de datos y a devolver un código
 * de confirmación **y una dirección donde el usuario pueda consultar cómo va**.
 * Eso último es lo que obliga a guardarlas: un endpoint que conteste un código
 * inventado y no lo recuerde no puede responder después, y es motivo de rechazo
 * en el App Review.
 *
 * No lleva `company_id` y no es un descuido: la petición llega ANTES de saber a
 * qué empresa pertenece la cuenta —el `signed_request` sólo trae el IGSID—, así
 * que el vínculo se resuelve al atenderla, no al recibirla.
 *
 * Guardada por `hasTable` como el resto de migraciones de este repo, después de
 * que una sin guardar tumbara producción siete minutos (9-sep-2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instagram_deletion_requests')) {
            return;
        }

        Schema::create('instagram_deletion_requests', function (Blueprint $table) {
            $table->id();
            // IGSID: identificador con ámbito de cuenta. No es un teléfono ni
            // sirve fuera de la cuenta que lo emitió.
            $table->string('instagram_user_id')->index();
            // Lo que se le devuelve a Meta y con lo que el usuario consulta.
            $table->string('confirmation_code', 64)->unique();
            $table->string('status', 20)->default('recibida');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_deletion_requests');
    }
};
