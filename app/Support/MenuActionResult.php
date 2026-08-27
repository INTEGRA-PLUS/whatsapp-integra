<?php

namespace App\Support;

/**
 * Lo que el bot hace después de ejecutar una acción del menú: qué le contesta
 * al cliente, si se queda esperando un dato y si el chat se le pasa a una
 * persona.
 *
 * Existe para que el servicio de acciones no envíe mensajes ni toque la base:
 * decide, y el job es el único que habla con Meta. Así una acción se puede
 * probar sin levantar cola ni simular la Cloud API.
 */
final class MenuActionResult
{
    private function __construct(
        public readonly string $text,
        public readonly ?string $step,
        public readonly array $context,
        public readonly bool $handoff,
    ) {}

    /** Se contesta y se acaba: la acción quedó resuelta. */
    public static function reply(string $text): self
    {
        return new self($text, null, [], false);
    }

    /** Se contesta con una pregunta y se espera la respuesta del cliente. */
    public static function ask(string $text, string $step, array $context): self
    {
        return new self($text, $step, $context, false);
    }

    /**
     * El bot no puede resolverlo (no identificamos al cliente, Integra no
     * responde): se contesta y el chat pasa a un asesor. Callar aquí es lo peor
     * que puede hacer un menú, porque el cliente ya no tiene a quién preguntar.
     */
    public static function escalate(string $text): self
    {
        return new self($text, null, [], true);
    }

    /** No se manda nada (la opción no tenía texto configurado). */
    public static function silent(): self
    {
        return new self('', null, [], false);
    }

    public function keepsWaiting(): bool
    {
        return $this->step !== null;
    }
}
