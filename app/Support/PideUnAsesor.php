<?php

namespace App\Support;

/**
 * El cliente está pidiendo hablar con una persona, en sus palabras.
 *
 * ## Por qué no lo decide el modelo
 *
 * Se intentó: el prompt del worker le pide que termine con un marcador cuando
 * haga falta un asesor. Y el prompt **llega** —se comprobó en la ejecución de
 * n8n— pero el modelo no lo escribe: contesta «procederé a comunicarlo con un
 * asesor» y se queda tan ancho. Dos veces seguidas con el cliente escribiendo
 * «comunícame con un asesor» en letras bien grandes (17-sep-2026).
 *
 * Pedir un humano es la petición que **menos** puede fallar de todo el chat: es
 * justo lo que alguien escribe cuando el bot ya le falló. Así que se reconoce
 * aquí, con reglas, y el modelo se queda como refuerzo y no como guardián.
 *
 * ## Por qué esta lista y no una más lista
 *
 * Porque el coste de equivocarse es asimétrico. Un falso positivo pasa el chat
 * a una persona —que es seguro, y además es lo que quiere quien escribió algo
 * parecido—; un falso negativo deja a un cliente pidiendo ayuda a un bot que le
 * dice que sí y no hace nada. Ante la duda, se deriva.
 *
 * Lo que NO entra: mencionar a un asesor sin pedirlo («el asesor me dijo»,
 * «¿ustedes tienen asesores?»). Por eso se exige un verbo de petición delante y
 * no basta con que aparezca la palabra.
 */
class PideUnAsesor
{
    /** Con quién quiere hablar. */
    private const PERSONA = '(asesor|asesora|agente|persona|humano|humana|alguien|operador|operadora|ejecutivo|ejecutiva|representante)';

    /**
     * Las formas en que se pide, tal como las escribe la gente en Colombia.
     *
     * Se aceptan sin tildes y en cualquier caja: nadie tilda escribiendo por
     * WhatsApp, y `sinTildes()` deja el texto plano antes de comparar.
     */
    private const FORMAS = [
        // «quiero hablar con un asesor», «necesito hablar con alguien»
        '/\b(hablar|charlar|conversar)\s+con\s+(un|una|el|la|algun|alguna)?\s*'.self::PERSONA.'/u',
        // «comunicame con un asesor», «me comunica con una persona»
        '/\b(comunic\w*|contact\w*|conect\w*|pas\w*|transfier\w*|deriv\w*)\s+(me\s+)?con\s+(un|una|el|la|algun|alguna)?\s*'.self::PERSONA.'/u',
        // «pasame a un asesor», «transfiereme a una persona»
        '/\b(pas\w*|transfier\w*|deriv\w*|mand\w*)\s*(me\s+)?(a|al|con)\s+(un|una|el|la)?\s*'.self::PERSONA.'/u',
        // «quiero un asesor», «necesito una persona», «dame un agente»
        '/\b(quiero|necesito|deseo|dame|requiero|solicito)\s+(hablar\s+con\s+)?(un|una|el|la)?\s*'.self::PERSONA.'/u',
        // «que me atienda alguien», «me puede atender una persona». El
        // pronombre va delante del verbo, que es como se habla: «me atienda»,
        // no «atienda me».
        '/\b(me\s+)?(atien\w+|atend\w+|ayud\w+)\s+(un|una|el|la|algun|alguna)?\s*'.self::PERSONA.'/u',
        // «asesor por favor», «agente humano»
        '/\b'.self::PERSONA.'\s+(por\s+favor|humano|real|de\s+verdad)\b/u',
    ];

    public static function loPide(?string $texto): bool
    {
        $plano = self::sinTildes(mb_strtolower(trim((string) $texto)));

        if ($plano === '') {
            return false;
        }

        // Un mensaje largo que menciona a un asesor de pasada casi nunca es una
        // petición: quien quiere una persona lo pide corto y claro. El corte es
        // generoso —una frase entera cabe— y evita disparar con un relato.
        if (mb_strlen($plano) > 160) {
            return false;
        }

        foreach (self::FORMAS as $forma) {
            if (preg_match($forma, $plano) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Sin tildes ni diéresis: nadie las escribe por WhatsApp. */
    private static function sinTildes(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
