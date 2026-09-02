<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Log;

/**
 * Reconoce cuándo un cliente está pidiendo que dejen de mandarle campañas.
 *
 * No decide: **avisa**. Marca la conversación y deja constancia en el hilo, y
 * es un agente quien confirma la baja con un clic desde Contactos. La razón es
 * el idioma: en Colombia «baja» es también dar de baja el *servicio*, y un
 * cliente que escribe «quiero la baja» normalmente está hablando de su internet,
 * no de la publicidad. Apuntarlo solo por la palabra sería adivinar, y adivinar
 * mal aquí significa dejar de avisarle de su factura.
 *
 * Por eso el listado es corto y exige que el mensaje sea **exactamente** eso:
 * quien escribe «STOP» a secas no está conversando, está pidiendo que pares.
 */
class OptOutRequest
{
    /**
     * Frases que, siendo el mensaje entero, no admiten otra lectura.
     */
    private const FRASES = [
        'stop',
        'baja',
        'dar de baja',
        'darme de baja',
        'no molestar',
        'no mas mensajes',
        'no quiero mas mensajes',
        'no enviar mas mensajes',
        'no me envien mas mensajes',
        'no me manden mas mensajes',
        'no publicidad',
        'no quiero publicidad',
        'eliminar de la lista',
        'sacarme de la lista',
        'cancelar suscripcion',
        'unsubscribe',
    ];

    /** Un mensaje más largo que esto ya es una conversación, no una orden. */
    private const MAX_LONGITUD = 40;

    public static function looksLikeOptOut(?string $texto): bool
    {
        $normalizado = self::normalizar($texto);

        if ($normalizado === '' || mb_strlen($normalizado) > self::MAX_LONGITUD) {
            return false;
        }

        return in_array($normalizado, self::FRASES, true);
    }

    /**
     * Marca la petición y la cuenta en el hilo, para que el agente la vea en el
     * sitio donde está mirando.
     */
    public static function flag(WhatsAppConversation $conversation, ?string $texto): void
    {
        if (!self::looksLikeOptOut($texto)) {
            return;
        }

        // Ya está fuera de las campañas: no hace falta pedir nada dos veces.
        if (self::alreadyOptedOut($conversation)) {
            return;
        }

        if ($conversation->opt_out_requested_at) {
            return;
        }

        try {
            $conversation->forceFill(['opt_out_requested_at' => now()])->save();

            ConversationNotice::record(
                $conversation,
                'El cliente pidió no recibir campañas. Un agente puede confirmarlo desde Contactos '
                . '— seguirá recibiendo respuestas y los avisos de su servicio.'
            );
        } catch (\Throwable $e) {
            // Detectar la petición es una ayuda, no parte de recibir el mensaje.
            Log::warning('No se pudo marcar la petición de baja', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function alreadyOptedOut(WhatsAppConversation $conversation): bool
    {
        $telefono = WhatsAppConversation::normalizeRecipient((string) $conversation->phone_number);

        if ($telefono === '') {
            return false;
        }

        return Contact::whereHas('conversations', fn ($q) => $q->whereKey($conversation->id))
            ->optedOut()
            ->exists()
            || Contact::where('phone_number', $telefono)->optedOut()->exists();
    }

    /**
     * Minúsculas, sin tildes, sin signos y con los espacios colapsados: «¡NO
     * MÁS MENSAJES!» y «no mas mensajes» son la misma petición.
     */
    private static function normalizar(?string $texto): string
    {
        $texto = mb_strtolower(trim((string) $texto));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $texto = preg_replace('/[^\p{L}\p{N}\s]/u', '', $texto);

        return trim(preg_replace('/\s+/', ' ', (string) $texto));
    }
}
