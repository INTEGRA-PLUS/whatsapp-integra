<?php

namespace App\Services;

use App\Jobs\ProcessOutOfHours;
use App\Models\BusinessHour;
use App\Models\Instance;
use App\Models\WhatsAppConversation;

class BusinessHoursService
{
    /**
     * Si la instancia tiene una regla activa de horario y el momento actual cae fuera de éste,
     * despacha el envío del mensaje configurado y retorna true. En caso contrario retorna false.
     */
    public function handleInbound(Instance $instance, WhatsAppConversation $conversation): bool
    {
        $rule = $this->findApplicableRule($instance);

        if (!$rule || $rule->isWithinHours()) {
            return false;
        }

        ProcessOutOfHours::dispatch($instance->id, $conversation->id);

        return true;
    }

    /**
     * Devuelve la ÚNICA regla aplicable a la instancia: la de instancia específica
     * tiene prioridad sobre la de "todas las instancias". Esta misma regla es la que
     * usa el Job para enviar, de modo que la decisión y el mensaje sean coherentes.
     */
    public function findApplicableRule(Instance $instance): ?BusinessHour
    {
        return BusinessHour::active()
            ->where('company_id', $instance->company_id)
            ->where(function ($q) use ($instance) {
                $q->whereNull('instance_id')->orWhere('instance_id', $instance->id);
            })
            ->orderByRaw('instance_id IS NULL')
            ->orderBy('created_at', 'desc')
            ->first();
    }
}
