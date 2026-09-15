<?php

namespace App\Support\Sentimiento;

/**
 * El color de una conversación, con su porqué.
 *
 * Es lo que se guarda en `whatsapp_conversations` y lo que ve el agente. Un
 * color sin motivo sería inútil —nadie se fía de un punto rojo que no explica
 * nada— y por eso el motivo no es opcional.
 */
readonly class Lectura
{
    public const VERDE = 'verde';

    public const AMARILLO = 'amarillo';

    public const ROJO = 'rojo';

    public const ORIGEN_MATRIZ = 'matriz';

    public const ORIGEN_IA = 'ia';

    public const ORIGEN_MANUAL = 'manual';

    /** Los tres, de mejor a peor. El orden es el de urgencia para la bandeja. */
    public const NIVELES = [self::VERDE, self::AMARILLO, self::ROJO];

    public function __construct(
        public string $nivel,
        public float $score,
        public string $motivo,
        public string $origen = self::ORIGEN_MATRIZ,
        public float $confianza = 0.5,
    ) {}

    public function esRojo(): bool
    {
        return $this->nivel === self::ROJO;
    }

    /** ¿Dice algo distinto de lo que ya había guardado? */
    public function cambiaRespectoA(?string $nivelAnterior): bool
    {
        return $this->nivel !== $nivelAnterior;
    }
}
