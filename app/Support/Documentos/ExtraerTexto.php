<?php

namespace App\Support\Documentos;

/**
 * Saca de un fichero los trozos de texto que se le van a poder mandar al modelo.
 *
 * Devuelve una lista de `['texto' => …, 'origen' => …]`. El `origen` es de dónde
 * salió dentro del documento —la página, la hoja y la fila— y no es decoración:
 * es lo que permite que la IA cite la fuente y que la empresa pueda auditar una
 * respuesta mala.
 *
 * ## Sin dependencias del sistema operativo
 *
 * DOCX y XLSX son zips con XML dentro, y `zip`, `dom` y `SimpleXML` ya están en
 * la imagen. El PDF va con `smalot/pdfparser`, que es PHP puro. Es a propósito:
 * meter `pdftotext` o LibreOffice engorda la imagen y añade un binario más que
 * mantener, para leer unos pocos ficheros al mes.
 *
 * ## Las hojas de cálculo no se parten como la prosa
 *
 * Un tarifario partido en trozos de 800 caracteres deja filas huérfanas de su
 * encabezado, que es como guardar «89.900» sin decir de qué. **Cada fila es un
 * trozo y lleva el encabezado pegado**, así que se explica sola y la búsqueda la
 * encuentra por cualquiera de sus campos. Ver `docs/plan-adjuntos-ia.md`.
 */
class ExtraerTexto
{
    /**
     * Lo que dura un trozo de prosa, en caracteres.
     *
     * 800 es aproximadamente un párrafo largo: suficiente para que el trozo se
     * entienda solo y corto para que la búsqueda pueda ser precisa. Un trozo
     * demasiado grande devuelve media página para responder a una línea.
     */
    private const TROZO = 800;

    /**
     * Cuánto se repite del trozo anterior.
     *
     * Porque una frase partida justo en el corte se pierde para los dos lados:
     * ni el trozo que la empieza ni el que la termina la contienen entera.
     */
    private const SOLAPE = 150;

    /** Tope de trozos por documento. Un Excel de 50.000 filas no entra entero. */
    public const MAXIMO_TROZOS = 3000;

    /**
     * @return list<array{texto: string, origen: ?string}>
     *
     * @throws DocumentoIlegible cuando no hay texto que sacar
     */
    public static function de(string $ruta, string $extension, string $nombre): array
    {
        $trozos = match (strtolower($extension)) {
            'pdf' => self::delPdf($ruta, $nombre),
            'docx' => self::deProsa(LectorDocx::texto($ruta), null),
            'txt' => self::deProsa((string) file_get_contents($ruta), null),
            'xlsx' => LectorXlsx::filas($ruta, $nombre),
            'csv' => LectorCsv::filas($ruta, $nombre),
            default => throw new DocumentoIlegible("No sabemos leer archivos .{$extension}."),
        };

        $trozos = array_values(array_filter(
            $trozos,
            fn ($t) => mb_strlen(trim($t['texto'])) >= 20
        ));

        if ($trozos === []) {
            throw new DocumentoIlegible(self::porQueNoHayNada($extension));
        }

        return array_slice($trozos, 0, self::MAXIMO_TROZOS);
    }

    /**
     * El aviso que ve el admin cuando el fichero no tiene texto.
     *
     * El caso frecuente, y con diferencia, es el PDF escaneado: muchos
     * reglamentos son una foto del papel. Desde fuera es idéntico a un PDF
     * normal, así que si el mensaje no lo nombra, el admin vuelve a subir el
     * mismo fichero convencido de que falló la subida.
     */
    private static function porQueNoHayNada(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => 'Este PDF no tiene texto: es una imagen escaneada. '
                .'Súbelo en Word, o pega su contenido en «Qué sabe de tu empresa».',
            'xlsx', 'csv' => 'La hoja está vacía, o no tiene una fila de encabezado con los nombres de las columnas.',
            default => 'El archivo está vacío o no se pudo leer su contenido.',
        };
    }

    /** Un trozo por página, y las páginas largas partidas en prosa. */
    private static function delPdf(string $ruta, string $nombre): array
    {
        $paginas = LectorPdf::paginas($ruta);
        $trozos = [];

        foreach ($paginas as $numero => $texto) {
            foreach (self::deProsa($texto, null) as $t) {
                $trozos[] = [
                    'texto' => $t['texto'],
                    'origen' => "{$nombre} · pág. {$numero}",
                ];
            }
        }

        return $trozos;
    }

    /**
     * Parte un texto corrido en trozos, cortando por donde no duela.
     *
     * Se busca el final de párrafo o de frase más cercano al corte en vez de
     * cortar en el carácter 800 exacto: un trozo que empieza a media frase le
     * llega al modelo como una cita mal hecha, y ahí es donde se inventa lo que
     * falta.
     *
     * @return list<array{texto: string, origen: ?string}>
     */
    public static function deProsa(string $texto, ?string $origen): array
    {
        $texto = self::limpiar($texto);

        if (trim($texto) === '') {
            return [];
        }

        $trozos = [];
        $largo = mb_strlen($texto);
        $desde = 0;

        while ($desde < $largo) {
            $corte = min(self::TROZO, $largo - $desde);
            $trozo = mb_substr($texto, $desde, $corte);

            // Sólo se busca un corte natural si queda documento por delante:
            // al último trozo no hay que recortarle nada.
            if ($desde + $corte < $largo) {
                $corte = self::corteNatural($trozo) ?? $corte;
                $trozo = mb_substr($texto, $desde, $corte);
            }

            $trozos[] = ['texto' => trim($trozo), 'origen' => $origen];

            // El avance nunca puede ser cero aunque el solape se coma el trozo
            // entero: un trozo más corto que el solape dejaría el bucle
            // girando sobre el mismo punto para siempre.
            $desde += max(1, $corte - self::SOLAPE);
        }

        return array_values(array_filter($trozos, fn ($t) => trim($t['texto']) !== ''));
    }

    /**
     * Dónde cortar sin partir una frase: fin de párrafo, y si no, fin de frase.
     *
     * Devuelve `null` si no hay ningún corte razonable en el último tercio —un
     * bloque sin puntos, como una tabla pegada— y entonces se corta a lo bruto,
     * que es mejor que devolver un trozo de dos caracteres.
     */
    private static function corteNatural(string $trozo): ?int
    {
        $minimo = (int) (mb_strlen($trozo) * 0.6);

        foreach (["\n\n", '. ', "\n"] as $marca) {
            $posicion = mb_strrpos($trozo, $marca);

            if ($posicion !== false && $posicion >= $minimo) {
                return $posicion + mb_strlen($marca);
            }
        }

        return null;
    }

    /**
     * Quita lo que sólo ocupa sitio en el prompt.
     *
     * Los PDF traen líneas partidas y rachas de espacios del maquetado, y cada
     * uno de esos caracteres es un token que se paga en cada consulta.
     */
    private static function limpiar(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\n{3,}/u', "\n\n", $texto) ?? $texto;

        return trim($texto);
    }
}
