<?php

namespace App\Support;

/**
 * La nota que la IA le deja al asesor al derivarle un chat, saneada.
 *
 * La escribe un flujo de n8n y se pinta en el hilo de la conversación, así que
 * es texto de fuera que acaba en la pantalla del equipo. El 18-sep-2026 llegó
 * esto, tal cual, en una nota:
 *
 *     La IA derivó el chat. · Motivo técnico: Ollama no respondió:
 *     {"error":{"message":"401 - \"{\\"error\\":\\"Unauthorized\\"}\\n"},
 *     "name":"AxiosError","stack":"AxiosError: Request failed with status code
 *     401\n at settle (/usr/local/lib/node_modules/n8n/node_modules/.pnpm/
 *     axios@1.18.0/node_modules/axios/dist/node/axios.cjs:2199:12)…
 *
 * Seis líneas de traza que ocupan más que la conversación, con rutas del
 * servidor y la versión de n8n dentro. Al asesor no le dice nada —él necesita
 * saber qué pedía el cliente— y a quien sí le diría algo no lo está leyendo
 * ahí: eso va al log.
 *
 * Lo que queda es la primera frase útil, sin JSON ni trazas, cortada a lo que
 * cabe en una pastilla del hilo.
 */
class NotaParaElAsesor
{
    /** Lo que cabe sin empujar la conversación fuera de la pantalla. */
    public const MAXIMO = 220;

    /** Lo que delata una traza o un volcado, y no una frase. */
    private const SEÑALES_DE_TRAZA = [
        'AxiosError', 'node_modules', 'at settle', '.cjs:', '.js:', 'stack',
        'Error: Request failed', '/usr/local/', '/var/www/',
    ];

    public static function limpia(?string $nota): ?string
    {
        $texto = trim((string) $nota);

        if ($texto === '') {
            return null;
        }

        // Se corta en el primer indicio de volcado: lo que venga después es
        // para el log, no para una persona que abre un chat.
        foreach (self::SEÑALES_DE_TRAZA as $señal) {
            $donde = mb_stripos($texto, $señal);

            if ($donde !== false) {
                $texto = mb_substr($texto, 0, $donde);
            }
        }

        // Y lo que quede de JSON suelto tampoco es una frase.
        $texto = preg_replace('/\{.*$/s', '', $texto) ?? $texto;
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);

        // Rematar en un separador colgando —«… · Motivo técnico:»— se lee como
        // si faltara algo, que es justo lo que pasó.
        $texto = rtrim($texto, " ·:-–—,;");

        if ($texto === '') {
            return null;
        }

        return mb_strlen($texto) > self::MAXIMO
            ? rtrim(mb_substr($texto, 0, self::MAXIMO - 1)).'…'
            : $texto;
    }
}
