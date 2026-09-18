<?php

namespace App\Support;

/**
 * El cliente está diciendo que ya no necesita nada.
 *
 * Es el reverso de [[PideUnAsesor]] y se escribe con el mismo criterio, pero el
 * riesgo va **al revés**: aquí un falso positivo **cierra la conversación de
 * alguien que seguía hablando**, que es de las peores cosas que puede hacer un
 * sistema de atención. Así que esta lista es corta, exige que el mensaje sea
 * corto, y ante la duda no cierra.
 *
 * Por eso tampoco entra un «gracias» suelto: en Colombia se dice «gracias» a
 * mitad de conversación cada tres mensajes. Lo que cierra es un «gracias» que
 * además despide —«listo, gracias»— o un «no» que responde a nuestra pregunta.
 */
class SeDespide
{
    /**
     * Sólo mensajes cortos.
     *
     * Quien se despide escribe cinco palabras. Quien escribe tres líneas está
     * contando algo, y dentro puede haber un «muchas gracias» que no cierra
     * nada.
     */
    private const MAXIMO = 60;

    private const FORMAS = [
        // «no, gracias», «ya no, muchas gracias», «nada mas gracias»
        '/\b(no|nada|ya\s+no|nada\s+mas|ninguna|nadamas)\b.{0,20}\bgracias\b/u',
        '/\bgracias\b.{0,20}\b(no|nada|ya\s+no|nada\s+mas|es\s+todo|eso\s+es\s+todo)\b/u',
        // «eso es todo», «seria todo», «asi esta bien»
        '/\b(eso|esо)?\s*(es|seria|era)\s+todo\b/u',
        '/\b(asi|ahi)\s+(esta|estamos)\s+(bien|perfecto)\b/u',
        // «listo gracias», «perfecto muchas gracias», «vale gracias»
        '/\b(listo|perfecto|vale|ok|okey|okay|entendido|dale)\b.{0,15}\b(gracias|muchas\s+gracias)\b/u',
        // «hasta luego», «chao», «que esté bien»
        '/\b(hasta\s+luego|hasta\s+pronto|nos\s+vemos|chao|chau|adios|buen\s+dia|feliz\s+dia|que\s+est[eé]\s+bien)\b/u',
        // «ya no necesito nada», «no necesito mas»
        '/\bno\s+(necesito|requiero|quiero)\s+(mas|nada|otra\s+cosa)\b/u',
        '/\bya\s+(no)?\s*(necesito|requiero)\b/u',
    ];

    public static function loDice(?string $texto): bool
    {
        $plano = self::sinTildes(mb_strtolower(trim((string) $texto)));

        if ($plano === '' || mb_strlen($plano) > self::MAXIMO) {
            return false;
        }

        // Una pregunta no es una despedida, por muchas gracias que lleve
        // delante: «gracias, ¿y el horario del sábado?» sigue esperando
        // respuesta.
        if (str_contains($plano, '?')) {
            return false;
        }

        foreach (self::FORMAS as $forma) {
            if (preg_match($forma, $plano) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function sinTildes(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
