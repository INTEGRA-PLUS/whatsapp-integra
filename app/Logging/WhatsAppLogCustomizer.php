<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class WhatsAppLogCustomizer
{
    /**
     * Customize the given logger instance.
     *
     * Increases Monolog's normalization depth to prevent truncation
     * of deeply nested WhatsApp webhook payloads.
     */
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $formatter = $handler->getFormatter();

            if (method_exists($formatter, 'setMaxNormalizeDepth')) {
                $formatter->setMaxNormalizeDepth(50);
            }

            if (method_exists($formatter, 'setMaxNormalizeItemCount')) {
                $formatter->setMaxNormalizeItemCount(5000);
            }
        }
    }
}
