<?php

namespace App\Support;

/**
 * Todo lo que la IA decidió sobre un mensaje.
 *
 * Antes viajaban sueltos el `MenuActionResult` y la nota para el asesor, y el
 * resto de la respuesta del flujo —el evento de negocio, a quién identificó, la
 * traza del modelo— se perdía entre `WhatsAppAiClient` y el job que ejecuta.
 * Agruparlos en un objeto es lo que deja añadir un dato nuevo sin volver a
 * tocar la firma de `ProcessWhatsAppMenu`, que ya iba por ocho parámetros.
 *
 * Es un valor plano a propósito: viaja serializado dentro de un job, así que no
 * puede contener modelos ni nada que dependa de la conexión a la base.
 */
final class AiDecision
{
    /**
     * @param ?string $note   Resumen de la IA para el asesor cuando deriva.
     * @param array   $meta   Traza del flujo (intención, confianza, modelo, ms).
     *                        Se guarda en la burbuja para poder auditar después
     *                        por qué la IA contestó lo que contestó.
     * @param ?string $event  Evento de negocio que el flujo pide emitir, ya
     *                        validado contra la lista conocida.
     * @param array   $eventData Cuerpo de ese evento.
     * @param array   $client A quién identificó el flujo en Integra:
     *                        {id, identificacion, nombre}. Vacío si a nadie.
     */
    public function __construct(
        public readonly MenuActionResult $result,
        public readonly ?string $note = null,
        public readonly array $meta = [],
        public readonly ?string $event = null,
        public readonly array $eventData = [],
        public readonly array $client = [],
    ) {}

    /** ¿El flujo identificó al cliente en Integra? */
    public function identifiedClient(): bool
    {
        return filled($this->client['identificacion'] ?? null)
            || filled($this->client['id'] ?? null);
    }
}
