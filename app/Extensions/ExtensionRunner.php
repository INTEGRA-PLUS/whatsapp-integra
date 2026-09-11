<?php

namespace App\Extensions;

use App\Extensions\Contracts\FiltersOutboundText;
use App\Extensions\Contracts\HandlesInboundMessage;
use App\Models\CompanyExtension;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Log;

/**
 * El único sitio desde el que se ejecuta una extensión.
 *
 * Que sea uno solo es lo que hace que la regla de aislamiento se pueda
 * comprobar de un vistazo: la empresa sale siempre de la conversación, nunca de
 * la sesión, porque estos ganchos corren en el webhook y en colas, donde no hay
 * usuario autenticado.
 *
 * Cada extensión va envuelta en su propio try/catch. Una extensión es un efecto
 * secundario sobre un mensaje que YA quedó guardado: si revienta, lo que no
 * puede pasar es que se lleve por delante el mensaje del cliente, ni que impida
 * correr a la siguiente. Es la misma regla que el webhook ya aplica a las
 * respuestas automáticas.
 */
class ExtensionRunner
{
    public function __construct(private ExtensionRegistry $registry) {}

    /**
     * Mensaje entrante recién guardado.
     *
     * `$conversation->instance` puede venir sin cargar desde el webhook, así que
     * se resuelve la empresa con cuidado: sin instancia no hay empresa y no hay
     * nada que ejecutar.
     */
    public function onInbound(WhatsAppConversation $conversation, WhatsAppMessage $message): void
    {
        $companyId = $this->companyIdOf($conversation);

        if (! $companyId) {
            return;
        }

        foreach ($this->registry->activeForHook($companyId, HandlesInboundMessage::class) as $installed) {
            $this->guard($installed, function () use ($installed, $conversation, $message) {
                /** @var HandlesInboundMessage $extension */
                $extension = $this->registry->find($installed->slug);
                $extension->onInboundMessage($conversation, $message, $installed);
            });
        }
    }

    /**
     * El texto de un mensaje saliente, justo antes de salir hacia Meta.
     *
     * `$default` es el texto tal y como la plataforma lo mandaba antes de que
     * existieran las extensiones (con el prefijo del agente incrustado). Sin
     * ninguna extensión de salida instalada se devuelve ese mismo texto sin
     * tocar: nadie que no haya instalado nada ve cambiar sus mensajes.
     *
     * En cuanto hay una instalada, la cadena parte del contenido CRUDO y es ella
     * quien decide la decoración completa. Encadenar la firma configurable
     * encima del prefijo fijo daría dos firmas en el mismo mensaje, que es justo
     * lo que la extensión venía a arreglar.
     *
     * Si una extensión falla se conserva el texto tal y como iba en ese punto de
     * la cadena: un mensaje sin firma llega; un mensaje que no sale, no.
     */
    public function outboundText(WhatsAppMessage $message, string $default): string
    {
        $companyId = $this->companyIdOf($message->conversation);

        if (! $companyId) {
            return $default;
        }

        $active = $this->registry->activeForHook($companyId, FiltersOutboundText::class);

        if ($active->isEmpty()) {
            return $default;
        }

        $text = (string) $message->content;

        foreach ($active as $installed) {
            $this->guard($installed, function () use ($installed, $message, &$text) {
                /** @var FiltersOutboundText $extension */
                $extension = $this->registry->find($installed->slug);
                $text = $extension->filterOutboundText($text, $message, $installed);
            });
        }

        return $text;
    }

    private function companyIdOf(?WhatsAppConversation $conversation): ?int
    {
        $companyId = $conversation?->instance?->company_id;

        return $companyId ? (int) $companyId : null;
    }

    private function guard(CompanyExtension $installed, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ Una extensión falló', [
                'slug' => $installed->slug,
                'company_id' => $installed->company_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
