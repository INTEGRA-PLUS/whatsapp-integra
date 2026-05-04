<?php

namespace App\Services;

use App\Jobs\ProcessAutoResponse;
use App\Models\Instance;
use App\Models\WhatsAppConversation;

class AutoResponseService
{
    public function handleInbound(Instance $instance, WhatsAppConversation $conversation, string $incomingText, string $wamid): void
    {
        ProcessAutoResponse::dispatch(
            $instance->id,
            $conversation->id,
            $incomingText,
            $wamid
        );
    }
}
