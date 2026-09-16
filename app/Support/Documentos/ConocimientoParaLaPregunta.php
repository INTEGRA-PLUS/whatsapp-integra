<?php

namespace App\Support\Documentos;

use App\Support\AiPrompt;

/**
 * Lo que la IA sabe del negocio **para este mensaje concreto**.
 *
 * Junta las dos fuentes en el campo `conocimiento` que ya viaja hacia n8n:
 *
 * 1. Lo que la empresa escribió a mano en «Qué sabe de tu empresa».
 * 2. Los trozos de sus documentos que responden a lo que acaba de preguntar el
 *    cliente.
 *
 * ## Por qué en el campo que ya existe y no en uno nuevo
 *
 * Porque añadir un campo al contrato con n8n cuesta tres sitios —el cliente de
 * Laravel, el nodo `Validar entrada` y el nodo `Armar job`, los dos últimos
 * reconstruyen el objeto campo por campo con una lista blanca— y **olvidarse de
 * uno se pierde en silencio**, sin error y sin log. Es exactamente lo que pasó
 * el 16-sep-2026 con el perfil del asistente y costó una mañana de diagnóstico.
 * Ver `docs/prompt-entrenable-por-empresa.md`.
 *
 * ## La cita va delante del trozo
 *
 * `[Tarifario 2026.xlsx · hoja «Planes hogar» · fila 4]` no es decoración: es lo
 * que permite que la IA diga de dónde lo sacó, que es lo que hace que el cliente
 * se lo crea, y lo que permite a la empresa auditar una respuesta mala.
 *
 * ## Y todo esto son DATOS, no instrucciones
 *
 * El texto sale de un fichero que subió un admin, y un PDF con «ignora las
 * instrucciones anteriores» dentro llega al prompt como cualquier otro trozo.
 * Por eso pasa por el mismo saneado que el texto escrito a mano: `AiPrompt`
 * quita los marcadores de turno y los delimitadores de bloque, que es como se
 * sale uno de un bloque delimitado. El bloque en sí, y el recordatorio que va
 * detrás, los pone el nodo `Preparar contexto`.
 */
class ConocimientoParaLaPregunta
{
    /**
     * Cuánto puede ocupar todo junto.
     *
     * 12.000 caracteres son unos 3.000 tokens: cabe lo escrito a mano más cinco
     * trozos holgados. El nodo `Preparar contexto` recorta a este mismo número,
     * así que subirlo aquí sin subirlo allí no sirve de nada — los trozos se
     * cortarían a media frase justo cuando empiezan a servir.
     */
    public const MAXIMO = 12000;

    public static function para(int $companyId, string $pregunta, string $escritoAMano): string
    {
        $fragmentos = BuscarFragmentos::para($companyId, $pregunta);

        if ($fragmentos === []) {
            return $escritoAMano;
        }

        $bloques = $escritoAMano !== '' ? [$escritoAMano] : [];

        foreach ($fragmentos as $fragmento) {
            $texto = AiPrompt::sanitizeInstructions((string) $fragmento['texto']);

            if (trim($texto) === '') {
                continue;
            }

            $bloques[] = $fragmento['origen']
                ? '['.$fragmento['origen']."]\n".$texto
                : $texto;
        }

        // Se recorta por trozos enteros y no con un `substr` al final: cortar a
        // mitad de un fragmento le entrega al modelo una cita incompleta, y ahí
        // es donde se inventa lo que falta.
        $salida = '';

        foreach ($bloques as $bloque) {
            $siguiente = $salida === '' ? $bloque : $salida."\n\n".$bloque;

            if (mb_strlen($siguiente) > self::MAXIMO) {
                break;
            }

            $salida = $siguiente;
        }

        return $salida;
    }
}
