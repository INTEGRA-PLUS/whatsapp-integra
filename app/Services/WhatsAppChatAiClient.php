<?php

namespace App\Services;

use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Support\AiAssistantProfile;
use App\Support\AiDecision;
use App\Support\MenuActionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le pasa un mensaje al flujo de IA de los chats.
 *
 * Es un proceso distinto al de los menús y por eso es un cliente aparte, no un
 * parámetro más de WhatsAppAiClient. La diferencia no es la URL: es el
 * contrato. Aquél habla `mensaje`, `conversacion`, `integra` y devuelve una
 * decisión; éste habla `message`, `user_id`, `tenant_id` y devuelve un texto.
 * Apuntar el mismo cliente a los dos es lo que dejó la IA muda.
 *
 * Como el de menús, devuelve una AiDecision y no envía nada: quien habla con
 * Meta sigue siendo ProcessWhatsAppMenu.
 */
class WhatsAppChatAiClient
{
    /**
     * ¿Tiene la plataforma este proceso configurado?
     *
     * Es distinto de que una empresa lo tenga encendido: sin esto, el
     * interruptor del panel no debería ni poder activarse.
     */
    public static function configured(): bool
    {
        return filled(config('services.ai_chat.webhook_url'))
            && filled(config('services.ai_chat.api_key'));
    }

    /** ¿Está encendido para esta empresa? */
    public static function enabledFor(int $companyId): bool
    {
        return self::configured() && CompanyIntegration::chatAiEnabled($companyId);
    }

    /**
     * @param string $wamid El id del mensaje en Meta. Viaja como `message_id`
     *                      para que el dedupe del gateway sirva de algo: si
     *                      Meta reintenta el webhook, el flujo reconoce el
     *                      duplicado y no vuelve a gastar una inferencia.
     *
     * @return ?AiDecision null cuando el flujo no se hace cargo: el mensaje
     *                     sigue su curso y acaba en la bandeja de un agente.
     */
    public function ask(
        Instance $instance,
        WhatsAppConversation $conversation,
        string $message,
        string $wamid
    ): ?AiDecision {
        if (! self::enabledFor($instance->company_id) || trim($message) === '' || $wamid === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-Api-Key' => (string) config('services.ai_chat.api_key')])
                ->timeout((int) config('services.ai_chat.timeout', 180))
                ->post((string) config('services.ai_chat.webhook_url'), [
                    'message' => $message,
                    // El gateway arma con esto la clave de su memoria de
                    // conversación: tiene que ser estable por cliente, no por
                    // mensaje.
                    'user_id' => (string) $conversation->phone_number,
                    'tenant_id' => (string) $instance->company_id,
                    'channel' => 'whatsapp',
                    'message_id' => $wamid,
                    // Quién es la IA de esta empresa. Sin esto el flujo sólo
                    // recibe `tenant_id` —un número— y no tiene con qué
                    // nombrar a nadie: de ahí que todas las empresas se
                    // presentaran con la misma identidad escrita en el prompt.
                    //
                    // `puede_ejecutar` en false: este flujo conversa y no
                    // tiene herramientas. Es lo que le dice al prompt que no
                    // confirme acciones que no puede llevar a cabo.
                    'asistente' => AiAssistantProfile::payload($instance->company_id, false),
                ]);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ El flujo de chat IA no respondió', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::channel('whatsapp')->warning('⚠️ El flujo de chat IA rechazó el mensaje', [
                'conversation_id' => $conversation->id,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            return null;
        }

        return $this->translate($response->json() ?? [], $conversation);
    }

    /**
     * Traduce la respuesta del gateway.
     *
     * El cuerpo llega como lo deje el nodo que responde en n8n, y ahí puede
     * cambiar sin que nadie toque este código: se busca el texto donde puede
     * estar en vez de exigir una forma exacta, pero sin inventar nada si no
     * está.
     */
    private function translate(array $data, WhatsAppConversation $conversation): ?AiDecision
    {
        // Un `status: duplicate` es Meta reintentando: la respuesta la generó
        // la primera entrega y volver a contestar sería escribir dos veces.
        if (($data['status'] ?? null) === 'duplicate') {
            Log::channel('whatsapp')->info('⏭️ Chat IA: el flujo reconoció el mensaje como duplicado', [
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

        // La forma habitual es un objeto; con `mode: each` n8n puede devolver
        // una lista de un elemento.
        $body = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;

        $answer = trim((string) ($body['answer'] ?? $body['text'] ?? $body['output'] ?? ''));

        if ($answer === '') {
            Log::channel('whatsapp')->info('ℹ️ El chat IA no devolvió texto; queda para un agente', [
                'conversation_id' => $conversation->id,
                'status' => $body['status'] ?? null,
                'degraded' => $body['degraded'] ?? null,
            ]);

            return null;
        }

        Log::channel('whatsapp')->info('🤖 El chat IA resolvió el mensaje', [
            'conversation_id' => $conversation->id,
            'modelo' => $body['model'] ?? null,
            'ms' => $body['latency_ms'] ?? null,
            'degraded' => $body['degraded'] ?? null,
        ]);

        // La traza viaja con la burbuja, igual que en la IA de menús: sin ella
        // no hay forma de abrir el chat semanas después y saber con qué modelo
        // se contestó eso.
        return new AiDecision(
            MenuActionResult::reply($answer),
            null,
            array_filter([
                'flujo' => 'chat',
                'modelo' => $body['model'] ?? null,
                'degradacion' => ($body['degraded'] ?? false) === true ? 'sí' : null,
                'trace_id' => $body['trace_id'] ?? null,
                'uso' => ['planificador' => ['ms' => $body['latency_ms'] ?? null]],
            ]),
        );
    }
}
