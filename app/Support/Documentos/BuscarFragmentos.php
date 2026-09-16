<?php

namespace App\Support\Documentos;

use App\Models\AiDocumento;
use App\Models\AiFragmento;
use App\Services\Embeddings;
use Illuminate\Support\Facades\DB;

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
     *
     * Va en la configuración y no aquí porque **el número depende del modelo**:
     * ver la tabla medida en `config/services.php`. Cambiar de modelo sin
     * volver a medir esto deja entrar basura, o no deja pasar nada.
     */
    private static function minimoParecido(): float
    {
        return (float) config('services.embeddings.minimo_parecido', 0.30);
    }

    /**
     * Cuántos fragmentos se comparan de una vez, como mucho.
     *
     * Comparar vectores en PHP es barato; **traerlos de la base no lo es**. A
     * 4 KB por vector, 800 fragmentos son 3 MB por mensaje, y esto corre en cada
     * mensaje entrante de cada cliente. Es el número a partir del cual esta
     * búsqueda sin índice deja de ser gratis.
     */
    private const UMBRAL_DE_ESCANEO = 800;

    /**
     * @return list<array{texto: string, origen: ?string, documento: ?int, parecido: ?float}>
     */
    /**
     * @param  bool  $apuntar  Si cuenta como uso de verdad de los documentos.
     *                         El probador de la pantalla pasa `false`: si
     *                         contara, el admin infla el número al que luego
     *                         mira para decidir si un documento sirve.
     */
    public static function para(
        int $companyId,
        string $pregunta,
        int $cuantos = self::CUANTOS,
        bool $apuntar = true
    ): array {
        $pregunta = trim($pregunta);

        if ($pregunta === '') {
            return [];
        }

        $vector = Embeddings::de($pregunta);

        $encontrados = $vector === null
            ? self::porPalabras($companyId, $pregunta, $cuantos)
            : self::porVector($companyId, $vector, $cuantos, $pregunta);

        if ($apuntar) {
            self::apuntarUso($encontrados);
        }

        return $encontrados;
    }

    /**
     * Apunta que estos documentos contestaron.
     *
     * Un `increment` por documento y no por fragmento: como mucho son cinco
     * filas, y esto corre en el camino de un mensaje entrante. Sin `updated_at`
     * porque no es una edición del documento — mover esa fecha haría que la
     * pantalla enseñara «modificado hoy» un tarifario que nadie ha tocado.
     *
     * @param  list<array{texto: string, origen: ?string, documento?: int}>  $encontrados
     */
    private static function apuntarUso(array $encontrados): void
    {
        $ids = array_values(array_unique(array_filter(
            array_column($encontrados, 'documento')
        )));

        if ($ids === []) {
            return;
        }

        AiDocumento::whereIn('id', $ids)->update([
            'usos' => DB::raw('usos + 1'),
            'ultimo_uso_at' => now(),
        ]);
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
    private static function porVector(int $companyId, array $vector, int $cuantos, string $pregunta = ''): array
    {
        $base = AiFragmento::where('company_id', $companyId)->whereNotNull('vector');

        // Cuántos hay antes de traérselos. Un vector de bge-m3 son 4 KB, así que
        // 2.000 fragmentos son 8 MB que se leerían **en cada mensaje entrante**
        // de esa empresa. Por debajo del umbral se comparan todos, que es lo
        // que da la mejor respuesta; por encima hay que acotar antes.
        $cuantosHay = (clone $base)->count();

        if ($cuantosHay > self::UMBRAL_DE_ESCANEO) {
            // Se recortan por palabras y se ordenan por vector después. Se
            // pierde algo de alcance —las palabras no saben que «préstamo» y
            // «crédito» son lo mismo— pero el orden final lo sigue poniendo el
            // significado, que es donde más se nota.
            //
            // Si un día esto es lo normal y no la excepción, es la señal de que
            // toca un índice vectorial de verdad y no seguir apretando aquí.
            $palabras = self::palabrasUtiles($pregunta);

            if ($palabras !== []) {
                $base->where(function ($q) use ($palabras) {
                    foreach ($palabras as $palabra) {
                        $q->orWhere('texto', 'like', '%'.$palabra.'%');
                    }
                });
            }

            $base->limit(self::UMBRAL_DE_ESCANEO);
        }

        $candidatos = $base->get(['texto', 'origen', 'vector', 'ai_documento_id']);

        if ($candidatos->isEmpty()) {
            return [];
        }

        return $candidatos
            ->map(fn (AiFragmento $f) => [
                'texto' => $f->texto,
                'origen' => $f->origen,
                'documento' => $f->ai_documento_id,
                'parecido' => round(Embeddings::parecido($vector, $f->vector ?? []), 3),
            ])
            ->filter(fn ($f) => $f['parecido'] >= self::minimoParecido())
            ->sortByDesc('parecido')
            ->take($cuantos)
            ->map(fn ($f) => [
                'texto' => $f['texto'],
                'origen' => $f['origen'],
                'documento' => $f['documento'],
                'parecido' => $f['parecido'],
            ])
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
            ->get(['texto', 'origen', 'ai_documento_id']);

        // Se ordenan por cuántas de las palabras aparecen: la base devuelve
        // igual de bien un fragmento que casa con una que otro que casa con
        // cuatro, y el segundo es casi siempre el que contesta.
        return $filas
            ->map(fn (AiFragmento $f) => [
                'texto' => $f->texto,
                'origen' => $f->origen,
                'documento' => $f->ai_documento_id,
                'aciertos' => count(array_filter(
                    $palabras,
                    fn ($p) => mb_stripos($f->texto, $p) !== false
                )),
            ])
            ->sortByDesc('aciertos')
            ->take($cuantos)
            ->map(fn ($f) => [
                'texto' => $f['texto'],
                'origen' => $f['origen'],
                'documento' => $f['documento'],
                // Sin vectores no hay parecido que enseñar: se buscó por
                // palabras, y un número inventado aquí se leería como si lo
                // hubiera.
                'parecido' => null,
            ])
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
