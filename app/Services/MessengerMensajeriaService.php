<?php

namespace App\Services;

use App\Models\Instance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Enviar por Messenger.
 *
 * Devuelve la misma forma que `MetaWhatsAppService` —`['success' => bool, 'data'
 * => [...]]`, con el identificador en `data.messages.0.id`— igual que el de
 * Instagram: así `DeliverWhatsAppMessage` sólo elige a quién llamar y todo lo de
 * después (guardar el wamid, marcar enviado, emitir en tiempo real, disparar el
 * webhook saliente) sigue siendo el mismo código para los tres canales.
 *
 * La diferencia con Instagram es el remitente: allí es `me` porque el token ya
 * dice de quién es la cuenta; aquí el token es **de la página**, y aun así hay
 * que mandar `/me/messages` con ese token. Lo que cambia de verdad es el host
 * —`graph.facebook.com`— y que el mensaje lleva `messaging_type`.
 */
class MessengerMensajeriaService
{
    /**
     * Texto libre dentro de la ventana de 24 horas.
     *
     * `messaging_type: RESPONSE` es obligatorio y no es decorativo: dice que
     * esto contesta a algo que escribió la persona. Sin él, Meta lo trata como
     * mensaje iniciado por el negocio y lo rechaza fuera de la ventana, que es
     * justo cuando más se nota.
     */
    public function enviarTexto(Instance $linea, string $destinatario, string $texto): array
    {
        $respuesta = Http::withToken($linea->access_token)
            ->post($this->url(), [
                'messaging_type' => 'RESPONSE',
                'recipient' => ['id' => $destinatario],
                'message' => ['text' => $texto],
            ]);

        if ($respuesta->failed()) {
            Log::channel('messenger')->error('❌ No se pudo enviar por Messenger', [
                'instancia' => $linea->id,
                'destinatario' => $destinatario,
                'respuesta' => $respuesta->json(),
            ]);

            return ['success' => false, 'error' => $respuesta->json()];
        }

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
     * Quién es el que escribe.
     *
     * El webhook manda el PSID y nada más, así que sin esta llamada la bandeja
     * enseña «Messenger · 651818» donde debería decir el nombre de la persona.
     *
     * En Facebook no hay `@usuario`: lo que se devuelve es nombre y apellido.
     * Por eso `username` va siempre a null y el nombre se arma con los dos
     * campos, que es lo que el asesor espera leer.
     *
     * @return array{username: ?string, name: ?string, profile_pic: ?string}|null
     */
    public function perfilDelCliente(Instance $linea, string $psid): ?array
    {
        try {
            $respuesta = Http::timeout(5)
                ->withToken($linea->access_token)
                ->get("https://graph.facebook.com/{$this->version()}/{$psid}", [
                    'fields' => 'first_name,last_name,profile_pic',
                ]);
        } catch (\Throwable $e) {
            Log::channel('messenger')->warning('⚠️ No se pudo leer el perfil de quien escribe', [
                'psid' => $psid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($respuesta->failed()) {
            Log::channel('messenger')->warning('⚠️ No se pudo leer el perfil de quien escribe', [
                'psid' => $psid,
                'respuesta' => $respuesta->json(),
            ]);

            return null;
        }

        $nombre = trim(implode(' ', array_filter([
            $respuesta->json('first_name'),
            $respuesta->json('last_name'),
        ])));

        return [
            'username' => null,
            'name' => $nombre !== '' ? $nombre : null,
            'profile_pic' => $respuesta->json('profile_pic'),
        ];
    }

    private function version(): string
    {
        return (string) config('services.meta.messenger.api_version', 'v23.0');
    }

    private function url(): string
    {
        return "https://graph.facebook.com/{$this->version()}/me/messages";
    }
}
