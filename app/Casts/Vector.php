<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Un vector de embeddings, guardado como binario en vez de como JSON.
 *
 * Desde fuera sigue siendo un `array<float>`: quien lo lee y quien lo escribe no
 * se entera de nada. Lo que cambia es el tamaño, y a esta escala eso importa.
 *
 * Un vector de `bge-m3` son 1.024 dimensiones. En JSON ocupa **7,4 KB**; en
 * float32 crudo, 4 KB exactos. La búsqueda carga los vectores de la empresa **en
 * cada mensaje entrante**, así que en una empresa con 600 fragmentos —cinco PDF
 * de treinta páginas, nada raro— la diferencia es entre leer 4,3 MB por mensaje
 * y leer 2,4.
 *
 * `g` es float32 en orden del procesador. Se pierde precisión frente al float64
 * de PHP, y da igual: los modelos de embeddings ya entregan float32, y el coseno
 * entre dos vectores normalizados no se mueve de forma apreciable por el séptimo
 * decimal.
 *
 * **Cambiar este formato obliga a reindexar** (`ia:revectorizar --todos`): lo
 * guardado antes se leería como ruido, sin fallar y sin avisar.
 */
class Vector implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $vector = unpack('g*', $value);

        // `unpack` devuelve claves desde 1. Se reindexa porque el coseno recorre
        // los dos vectores por posición, y uno que empiece en 1 y otro en 0 da
        // un parecido absurdo sin que nada falle.
        return $vector === false ? null : array_values($vector);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        // Se acepta que llegue ya empaquetado: el job escribe por lotes con
        // `update()`, que no pasa por el cast.
        if (is_string($value)) {
            return $value;
        }

        return pack('g*', ...array_map('floatval', $value));
    }
}
