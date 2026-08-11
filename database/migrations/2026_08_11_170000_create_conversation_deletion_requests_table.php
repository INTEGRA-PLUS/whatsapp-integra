<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peticiones de eliminación de una conversación pendientes de aprobación.
 *
 * Borrar un chat arrastra todos sus mensajes y adjuntos y no se puede deshacer,
 * así que quien no tiene el permiso `chat.delete` ya no borra: deja la petición
 * y un aprobador decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_deletion_requests', function (Blueprint $table) {
            $table->id();

            // nullOnDelete y no cascade: aprobar la petición borra justamente la
            // conversación, y con cascade el registro de quién pidió y quién
            // autorizó se destruiría en el mismo momento en que hace falta.
            // La petición sobrevive al chat como constancia de lo ocurrido.
            $table->foreignId('conversation_id')->nullable()
                ->constrained('whatsapp_conversations')->nullOnDelete();

            // Denormalizado a propósito: autorizar y listar las pendientes de una
            // empresa no debería exigir un join contra instancias.
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // nullOnDelete en ambos: si se borra el usuario, la petición queda
            // como registro de lo que pasó en vez de desaparecer.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->text('reason')->nullable();           // motivo de quien pide
            $table->text('review_note')->nullable();      // motivo de quien resuelve
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            // Las dos consultas reales: las pendientes de una empresa y si esta
            // conversación ya tiene una en curso.
            $table->index(['company_id', 'status']);
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_deletion_requests');
    }
};
