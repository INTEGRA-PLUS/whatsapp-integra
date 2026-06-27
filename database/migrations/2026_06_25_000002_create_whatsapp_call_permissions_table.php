<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_call_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('instance_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('wa_id');
            // pending: solicitado, sin respuesta | granted: usuario aceptó
            // rejected: usuario rechazó | expired: venció la ventana de permiso
            $table->enum('status', ['pending', 'granted', 'rejected', 'expired'])->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('instance_id')->references('id')->on('instances')->onDelete('cascade');
            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->nullOnDelete();
            // Un único estado de permiso vigente por contacto dentro de cada instancia
            $table->unique(['instance_id', 'wa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_call_permissions');
    }
};
