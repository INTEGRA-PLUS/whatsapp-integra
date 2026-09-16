<?php

namespace App\Support;

/**
 * ¿Esta variable está puesta de verdad, o es el hueco donde había que ponerla?
 *
 * Existe porque el mismo error ha pasado tres veces en este proyecto, y las tres
 * fueron caras de diagnosticar:
 *
 * - `META_APP_SECRETS` vacía tumbó todos los webhooks de Meta con 403 tras un
 *   despliegue, sin que nada dijera por qué.
 * - Los tres secretos de OnePay se quedaron con el texto de ejemplo literal
 *   —`<el appkey de la cuenta>`— al copiar un comando de la documentación.
 * - `META_ES_CONFIG_ID` desapareció en un rebuild y el botón de conectar
 *   WhatsApp dejó de pintarse, también en silencio.
 *
 * El patrón común no es que falten: es que **están puestas con algo que no
 * sirve**, y `filled()` dice que sí. Un token que es la frase «pon aquí tu
 * token» pasa cualquier comprobación de existencia, viaja al proveedor y vuelve
 * como un 401 que alguien tiene que ir a buscar al log.
 *
 * Esto no valida que el valor sea correcto —eso sólo lo sabe el proveedor— sino
 * que alguien llegó a escribirlo.
 */
class Configuracion
{
    /**
     * Las formas que tiene un hueco sin rellenar.
     *
     * `<...>` es lo que se copia de la documentación. `tu-token`, `xxx` y
     * `cambiar` son lo que se escribe para «probar luego». Van en minúsculas
     * porque la comparación se hace sobre el valor pasado a minúsculas.
     */
    private const MARCADORES = ['xxx', 'xxxx', 'cambiar', 'cambiame', 'pendiente',
        'por-definir', 'todo', 'null', 'none', 'ejemplo', 'example'];

    public static function puesta(mixed $valor): bool
    {
        if (! is_string($valor)) {
            return filled($valor);
        }

        $valor = trim($valor);

        if ($valor === '') {
            return false;
        }

        // `<lo que sea>`: el marcador de la documentación, copiado tal cual.
        if (str_starts_with($valor, '<') && str_ends_with($valor, '>')) {
            return false;
        }

        $minusculas = mb_strtolower($valor);

        if (in_array($minusculas, self::MARCADORES, true)) {
            return false;
        }

        // `tu-appkey`, `su_token`, `your-secret`: lo que queda de una plantilla.
        return ! preg_match('/^(tu|su|your|mi|my)[-_ ]/u', $minusculas);
    }

    /** El valor si está puesto, y `null` si es un hueco. */
    public static function valor(mixed $valor): ?string
    {
        return self::puesta($valor) ? trim((string) $valor) : null;
    }
}
