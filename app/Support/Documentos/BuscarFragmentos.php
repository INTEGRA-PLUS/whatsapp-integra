<?php

namespace App\Support\Documentos;

use App\Models\AiFragmento;
use App\Services\Embeddings;

/**
 * Qué trozos de los documentos de una empresa responden a lo que preguntó el
 * cliente.
 *
 * Es la pieza que hace que subir documentos sirva de algo. Sin ella habría que
 * pegarle al modelo los cinco documentos enteros en **cada mensaje** —unos
 * 60.000 tokens, sobre el flujo más caro que hay— y encima contestaría peor: lo
 * que se pierde en medio de un contexto enorme es justo el dato concreto que el
 * cliente preguntó.
 *
 * ## Dos formas de buscar, y la mala también sirve
 *
 * Con vectores se entiende que «préstamo» y «crédito» son lo mismo. Sin ellos
 * —porque no hay modelo configurado, o porque el documento se subió antes de
 * que lo hubiera— se cae a buscar palabras sueltas. Es peor, pero contesta, y
 * contestar peor es mejor que no contestar.
 *
 * ## El aislamiento
 *
 * Todas las consultas llevan su `where('company_id', …)`. Aquí no hay global
 * scopes y esto corre en cada mensaje entrante: un trozo del tarifario de una
 * ISP contestándole al cliente de otra es el peor fallo posible de todo esto, y
 * no lanzaría ningún error.
 */
class BuscarFragmentos
{
    /** Cuántos trozos se le mandan al modelo. */
    public const CUANTOS = 5;

    /**
     * Por debajo de esto, el trozo no habla de lo que preguntó el cliente.
     *
     * Sin un mínimo siempre salen cinco trozos, también cuando la pregunta no
     * tiene nada que ver con lo que hay en los documentos —un «hola» devuelve
     * los cinco párrafos menos malos— y el modelo acaba contestando con el
     * reglamento a quien sólo saludaba.
     */
    private const MINIMO_PARECIDO = 0.35;

    /**
     * @return list<array{texto: string, origen: ?string}>
     */
    public static function para(int $companyId, string $pregunta, int $cuantos = self::CUANTOS): array
    {
        $pregunta = trim($pregunta);

        if ($pregunta === '') {
            return [];
        }

        $vector = Embeddings::de($pregunta);

        return $vector === null
            ? self::porPalabras($companyId, $pregunta, $cuantos)
            : self::porVector($companyId, $vector, $cuantos);
    }

    /**
     * Los más cercanos, comparando en PHP.
     *
     * Sin base vectorial a propósito: con unos cientos de trozos por empresa
     * esto son milisegundos, y montar la infraestructura de un problema que no
     * se tiene es cómo se pierde una semana. El día que una empresa tenga
     * decenas de miles, esto deja de valer y hay que mover la comparación a un
     * índice de verdad.
     */
    private static function porVector(int $companyId, array $vector, int $cuantos): array
    {
        $candidatos = AiFragmento::where('company_id', $companyId)
            ->whereNotNull('vector')
            ->get(['texto', 'origen', 'vector']);

        if ($candidatos->isEmpty()) {
            return [];
        }

        return $candidatos
            ->map(fn (AiFragmento $f) => [
                'texto' => $f->texto,
                'origen' => $f->origen,
                'parecido' => Embeddings::parecido($vector, $f->vector ?? []),
            ])
            ->filter(fn ($f) => $f['parecido'] >= self::MINIMO_PARECIDO)
            ->sortByDesc('parecido')
            ->take($cuantos)
            ->map(fn ($f) => ['texto' => $f['texto'], 'origen' => $f['origen']])
            ->values()
            ->all();
    }

    /**
     * El plan B: las palabras de la pregunta que valen algo.
     *
     * Se tiran las de menos de cuatro letras y las vacías. Sin eso, «de», «la» y
     * «que» aparecen en todos los fragmentos y la búsqueda devuelve los cinco
     * primeros del documento, sea cual sea la pregunta.
     */
    private static function porPalabras(int $companyId, string $pregunta, int $cuantos): array
    {
        $palabras = self::palabrasUtiles($pregunta);

        if ($palabras === []) {
            return [];
        }

        $filas = AiFragmento::where('company_id', $companyId)
            ->where(function ($q) use ($palabras) {
                foreach ($palabras as $palabra) {
                    $q->orWhere('texto', 'like', '%'.$palabra.'%');
                }
            })
            ->limit(200)
            ->get(['texto', 'origen']);

        // Se ordenan por cuántas de las palabras aparecen: la base devuelve
        // igual de bien un fragmento que casa con una que otro que casa con
        // cuatro, y el segundo es casi siempre el que contesta.
        return $filas
            ->map(fn (AiFragmento $f) => [
                'texto' => $f->texto,
                'origen' => $f->origen,
                'aciertos' => count(array_filter(
                    $palabras,
                    fn ($p) => mb_stripos($f->texto, $p) !== false
                )),
            ])
            ->sortByDesc('aciertos')
            ->take($cuantos)
            ->map(fn ($f) => ['texto' => $f['texto'], 'origen' => $f['origen']])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private static function palabrasUtiles(string $pregunta): array
    {
        $vacias = ['para', 'como', 'cual', 'cuales', 'donde', 'cuando', 'porque', 'tiene',
            'tienen', 'quiero', 'saber', 'hola', 'buenas', 'favor', 'sobre', 'este', 'esta',
            'esto', 'ustedes', 'puedo', 'podria', 'necesito'];

        $limpia = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', mb_strtolower($pregunta)) ?? '';

        $palabras = array_filter(
            preg_split('/\s+/u', $limpia) ?: [],
            fn ($p) => mb_strlen($p) >= 4 && ! in_array($p, $vacias, true)
        );

        return array_values(array_unique($palabras));
    }
}
