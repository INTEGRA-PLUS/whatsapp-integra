<?php

use App\Models\Instance;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal de llamadas por instancia: solo agentes de la empresa dueña de la instancia.
Broadcast::channel('instance.{instanceId}', function ($user, $instanceId) {
    return Instance::where('id', $instanceId)
        ->where('company_id', $user->company_id)
        ->exists();
});
