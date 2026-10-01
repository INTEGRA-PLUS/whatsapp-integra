<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La captura de un pago que manda el cliente por WhatsApp, leída por el modelo
 * de visión y esperando a que una persona la apruebe.
 *
 * Lleva `company_id` propio aunque se podría llegar a él saltando por la
 * instancia: es una tabla de dinero, y la consulta que la lista no debe
 * depender de acordarse del preámbulo de instancias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes_de_pago', function (Blueprint $table) {
            $table->id();
            // **Sin claves foráneas, a propósito.** Con cualquier FK hacia
            // `whatsapp_messages` —aunque fuera la única—, MySQL dejaba a medias
            // el borrado en cascada de una instancia entera, sin error:
            // sobrevivían mensajes de su historial (lo pilló
            // BorradoDeInstanciaTest el 30-sep-2026; con la FK falla siempre,
            // sin ella pasa). Los comprobantes se borran a mano allí donde se
            // borra historial: `InstanceController::destroy` y el borrado
            // aprobado de una conversación.
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('instance_id')->index();
            $table->unsignedBigInteger('conversation_id')->index();
            // Uno por foto: el webhook puede reintentar y el job también.
            $table->unsignedBigInteger('whatsapp_message_id')->unique();

            // pendiente → aprobando → aprobado | revisar ; pendiente → rechazado
            $table->string('estado', 20)->default('pendiente');

            // Lo que leyó el modelo, tal cual y ya normalizado.
            $table->json('lectura')->nullable();
            $table->decimal('monto', 14, 2)->nullable();
            $table->date('fecha')->nullable();
            $table->string('referencia', 120)->nullable();
            // Sin espacios, guiones ni ceros a la izquierda: «00123-456» y
            // «123456» son el mismo pago y así se buscan.
            $table->string('referencia_normalizada', 120)->nullable();
            $table->string('banco', 120)->nullable();
            $table->string('destino', 190)->nullable();

            // Para avisar de la misma captura mandada dos veces.
            $table->char('imagen_sha256', 64)->nullable();
            $table->unsignedBigInteger('duplicado_de_id')->nullable();

            // Lo que la persona aprobó, que puede no ser lo que leyó el modelo.
            $table->unsignedBigInteger('factura_id')->nullable();
            $table->string('factura_codigo', 60)->nullable();
            $table->decimal('monto_aprobado', 14, 2)->nullable();
            $table->json('resultado')->nullable();
            $table->string('error', 500)->nullable();

            $table->unsignedBigInteger('revisado_por')->nullable();
            $table->timestamp('revisado_at')->nullable();
            $table->string('motivo_rechazo', 255)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'estado']);
            $table->index(['company_id', 'imagen_sha256']);
            $table->index(['company_id', 'referencia_normalizada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes_de_pago');
    }
};
