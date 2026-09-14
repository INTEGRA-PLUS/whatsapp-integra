<?php

namespace App\Support\Sentimiento;

/**
 * Lo que da de sí **un** mensaje suelto.
 *
 * Existe aparte de `Lectura` porque son dos cosas distintas y confundirlas es el
 * error de diseño clásico del análisis de sentimiento: esto es la lectura del
 * termómetro en un instante; `Lectura` es el diagnóstico de la conversación
 * entera. Un mensaje puntúa; una conversación tiene color.
 */
readonly class Puntuacion
{
    /**
     * @param float $score  De -1 (lo peor) a 1 (lo mejor). 0 es "no dice nada",
     *                      que NO es lo mismo que "está neutro y tranquilo".
     * @param list<string> $categorias Qué hizo saltar la aguja, para poder
     *                                 explicar el color sin adivinar.
     */
    public function __construct(
        public float $score,
        public array $categorias = [],
    ) {}

    /** ¿Este mensaje aporta algo, o es un "ok" del que no se deduce nada? */
    public function dice(): bool
    {
        return $this->categorias !== [];
    }
}
