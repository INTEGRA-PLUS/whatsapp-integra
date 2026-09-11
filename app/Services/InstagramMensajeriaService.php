<?php

namespace App\Services;

use App\Models\Instance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Enviar por Instagram Direct.
 *
 * Devuelve la misma forma que `MetaWhatsAppService` —`['success' => bool, 'data'
 * => [...], 'error' => ...]`, con el identificador en `data.messages.0.id`— a
 * propósito: así `DeliverWhatsAppMessage` sólo tiene que elegir a quién llamar,
 * y todo lo de después (guardar el wamid, marcar enviado, emitir en tiempo real,
 * disparar el webhook saliente) sigue siendo el mismo código para los dos
 * canales.
 */
class InstagramMensajeriaService
{
    /**
     * Texto libre dentro de la ventana de 24 horas.
     *
     * El host es `graph.instagram.com` y el remitente es `me`: con el token de
     * la cuenta, Meta ya sabe quién envía. No hay `phone_number_id` que pasar
     * porque no hay número en ningún sitio de este canal.
     */
    public function enviarTexto(Instance $linea, string $destinatario, string $texto): array
    {
        $respuesta = Http::withToken($linea->access_token)
            ->post($this->url(), [
                'recipient' => ['id' => $destinatario],
                'message' => ['text' => $texto],
            ]);

        if ($respuesta->failed()) {
            Log::channel('instagram')->error('❌ No se pudo enviar por Instagram', [
                'instancia' => $linea->id,
                'destinatario' => $destinatario,
                'respuesta' => $respuesta->json(),
            ]);

            return ['success' => false, 'error' => $respuesta->json()];
        }

        // Instagram contesta `{"recipient_id":"...","message_id":"..."}`. Se
        // traduce a la forma de WhatsApp para que el resto del camino no tenga
        // que saber por qué canal salió.
        return [
            'success' => true,
            'data' => [
                'messages' => [
                    ['id' => $respuesta->json('message_id')],
                ],
            ],
        ];
    }

    private function url(): string
    {
        $version = config('services.meta.instagram.api_version', 'v23.0');

        return "https://graph.instagram.com/{$version}/me/messages";
    }
}
