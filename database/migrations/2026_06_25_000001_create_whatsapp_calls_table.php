<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('instance_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            // Identificador de la llamada que asigna Meta (WACID)
            $table->string('wacid')->unique();
            $table->enum('direction', ['inbound', 'outbound']);
            // ringing: entra/timbra | connecting: aceptada, negociando media
            // in_progress: con audio | completed: finalizó OK | missed: no contestada
            // rejected: rechazada | failed: error | canceled: cancelada antes de conectar
            $table->enum('status', [
                'ringing', 'connecting', 'in_progress',
                'completed', 'missed', 'rejected', 'failed', 'canceled',
            ])->default('ringing');
            $table->string('from', 20)->nullable();
            $table->string('to', 20)->nullable();
            // SDP de la oferta de WhatsApp (offer) y nuestra respuesta (answer)
            $table->longText('offer_sdp')->nullable();
            $table->longText('answer_sdp')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('instance_id')->references('id')->on('instances')->onDelete('cascade');
            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->nullOnDelete();
            $table->foreign('handled_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['instance_id', 'status']);
            $table->index('conversation_id');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_calls');
    }
};
