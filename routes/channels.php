<?php

use App\Models\Instance;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal de llamadas, mensajes y cambios de conversación por instancia: solo
// agentes de la empresa dueña de la instancia.
Broadcast::channel('instance.{instanceId}', function ($user, $instanceId) {
    return Instance::where('id', $instanceId)
        ->where('company_id', $user->company_id)
        ->exists();
});

/**
 * Canal de PRESENCIA por conversación: quién tiene este chat abierto ahora
 * mismo, y por dónde viajan los avisos de "está escribiendo" (whispers, que
 * van cliente-a-cliente sin pasar por PHP ni tocar la base de datos).
 *
 * Evita el clásico "dos agentes contestando lo mismo": al abrir un hilo se ve
 * al instante quién más lo está mirando.
 *
 * Lo que se devuelve aquí lo recibe TODO el que esté en el canal, así que se
 * manda solo lo justo para pintar el avatar: nada de correo ni teléfono.
 */
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $belongsToCompany = WhatsAppConversation::where('id', $conversationId)
        ->whereHas('instance', fn ($q) => $q->where('company_id', $user->company_id))
        ->exists();

    if (! $belongsToCompany) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
