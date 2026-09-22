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

    /**
     * Quién es el que escribe: su usuario, su nombre y su foto.
     *
     * El webhook **no trae nada de esto**. Manda el IGSID y poco más, así que
     * sin esta llamada el chat enseña «Instagram · 651818» —los últimos dígitos
     * de un identificador— donde debería decir «@alejo.higuita». El asesor no
     * sabe con quién habla, y en Contactos queda una ficha que no se puede
     * buscar por nombre.
     *
     * Se pide una sola vez, al abrir la conversación, y no en cada mensaje: es
     * un viaje a Meta dentro del webhook y éste tiene que contestar rápido o
     * Meta reintenta el lote entero.
     *
     * Devuelve `null` sin ruido si falla. Que no se sepa el nombre no puede
     * costar el mensaje, que es lo que de verdad importa guardar.
     *
     * @return array{username: ?string, name: ?string, profile_pic: ?string}|null
     */
    public function perfilDelCliente(Instance $linea, string $igsid): ?array
    {
        try {
            $respuesta = Http::timeout(5)
                ->withToken($linea->access_token)
                ->get("https://graph.instagram.com/{$this->version()}/{$igsid}", [
                    'fields' => 'name,username,profile_pic',
                ]);
        } catch (\Throwable $e) {
            Log::channel('instagram')->warning('⚠️ No se pudo leer el perfil de quien escribe', [
                'igsid' => $igsid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($respuesta->failed()) {
            Log::channel('instagram')->warning('⚠️ No se pudo leer el perfil de quien escribe', [
                'igsid' => $igsid,
                'respuesta' => $respuesta->json(),
            ]);

            return null;
        }

        return [
            'username' => $respuesta->json('username'),
            'name' => $respuesta->json('name'),
            'profile_pic' => $respuesta->json('profile_pic'),
        ];
    }

    private function version(): string
    {
        return config('services.meta.instagram.api_version', 'v23.0');
    }

    private function url(): string
    {
        return "https://graph.instagram.com/{$this->version()}/me/messages";
    }
}
