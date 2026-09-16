<?php

namespace App\Services;

use App\Support\Configuracion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Convierte texto en vectores. El único sitio que habla con el modelo.
 *
 * Que sea el único importa más de lo que parece: **los vectores de dos modelos
 * distintos no se pueden comparar entre sí**, así que el día que se cambie de
 * modelo hay que reindexar todo. Con una sola puerta, cambiarlo es tocar una
 * variable de entorno y lanzar `ia:revectorizar`; repartido por tres sitios, es
 * una búsqueda que devuelve resultados absurdos sin que nada falle.
 *
 * ## Por qué corre en casa
 *
 * Ollama Cloud no ofrece ningún modelo de embeddings —su catálogo en la nube son
 * modelos de chat— así que la cuenta que ya está conectada no sirve. Y como esta
 * función va incluida en el complemento de IA sin cobrarse aparte, el coste
 * tiene que ser **fijo**: un proveedor por token convierte cada mensaje de cada
 * cliente en una factura variable sobre algo que no factura.
 *
 * ## Nunca lanza
 *
 * Si el modelo no responde se devuelve `null` y se sigue. Un documento sin
 * vectores se busca por palabras, que es peor pero contesta; una excepción aquí
 * tumbaría la respuesta al cliente por no poder hacer una búsqueda que es una
 * mejora, no un requisito.
 */
class Embeddings
{
    /**
     * Cuántos textos se mandan de una vez.
     *
     * El modelo corre en CPU y comparte servidor con todo lo demás: lotes
     * grandes lo dejan ocupado varios minutos seguidos y de eso se entera el
     * resto del sistema.
     */
    public const LOTE = 16;

    public static function configurado(): bool
    {
        return Configuracion::puesta(config('services.embeddings.url'));
    }

    /** El vector de un texto, o `null` si no se pudo. */
    public static function de(string $texto): ?array
    {
        return self::deVarios([$texto])[0] ?? null;
    }

    /**
     * Los vectores de varios textos, en el mismo orden que entraron.
     *
     * El orden es el contrato: quien llama empareja cada vector con su
     * fragmento por posición. Por eso un fallo devuelve una lista de nulos del
     * mismo tamaño y no una lista corta, que descolocaría todo lo demás.
     *
     * @param  list<string>  $textos
     * @return list<?array<float>>
     */
    public static function deVarios(array $textos): array
    {
        if ($textos === []) {
            return [];
        }

        if (! self::configurado()) {
            return array_fill(0, count($textos), null);
        }

        try {
            $respuesta = Http::acceptJson()
                ->timeout((int) config('services.embeddings.timeout', 120))
                ->post(rtrim((string) config('services.embeddings.url'), '/').'/api/embed', [
                    'model' => (string) config('services.embeddings.model'),
                    'input' => array_values($textos),
                ]);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ El modelo de embeddings no respondió', [
                'error' => $e->getMessage(),
                'textos' => count($textos),
            ]);

            return array_fill(0, count($textos), null);
        }

        if (! $respuesta->successful()) {
            Log::channel('whatsapp')->warning('⚠️ El modelo de embeddings rechazó la petición', [
                'status' => $respuesta->status(),
                'body' => mb_substr((string) $respuesta->body(), 0, 300),
            ]);

            return array_fill(0, count($textos), null);
        }

        $vectores = $respuesta->json('embeddings') ?? [];
        $salida = [];

        foreach ($textos as $i => $ignorado) {
            $vector = $vectores[$i] ?? null;

            $salida[] = is_array($vector) && $vector !== []
                ? array_map('floatval', $vector)
                : null;
        }

        return $salida;
    }

    /**
     * Cuánto se parecen dos vectores: de -1 a 1.
     *
     * Coseno y no distancia euclídea porque lo que importa es la dirección, no
     * la magnitud: un párrafo largo y uno corto sobre lo mismo apuntan al mismo
     * sitio pero tienen tamaños muy distintos.
     */
    public static function parecido(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return -1.0;
        }

        $producto = 0.0;
        $normaA = 0.0;
        $normaB = 0.0;

        foreach ($a as $i => $valor) {
            $producto += $valor * $b[$i];
            $normaA += $valor * $valor;
            $normaB += $b[$i] * $b[$i];
        }

        if ($normaA <= 0.0 || $normaB <= 0.0) {
            return -1.0;
        }

        return $producto / (sqrt($normaA) * sqrt($normaB));
    }
}
