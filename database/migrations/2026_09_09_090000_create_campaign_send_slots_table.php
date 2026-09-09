<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El reloj de envío de cada número de WhatsApp.
     *
     * Meta cuenta los mensajes por `phone_number_id`, no por campaña, así que
     * el ritmo tiene que vivir en algún sitio que las campañas compartan. Una
     * fila por instancia con "hasta cuándo están repartidos los turnos": quien
     * quiere encolar pide su tramo, lo reserva bajo bloqueo y mueve la marca.
     *
     * Va en tabla aparte y no en `instances` a propósito. La fila de la
     * instancia la tocan los webhooks entrantes en caliente, y bloquearla cada
     * 200 destinatarios para repartir una campaña metería a los mensajes que
     * llegan en una cola de espera que no tiene nada que ver con ellos.
     */
    public function up(): void
    {
        Schema::create('campaign_send_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();

            // Hasta aquí están repartidos los turnos. Nulo o pasado significa
            // que el número está libre y se puede empezar ya.
            $table->dateTime('next_slot_at')->nullable();

            $table->timestamps();

            // Una sola fila por número: es lo que convierte el reparto en
            // exclusivo. Sin esto, dos campañas simultáneas crearían cada una
            // la suya y volveríamos a tener dos relojes.
            $table->unique('instance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_send_slots');
    }
};
