<?php

namespace App\Support\Documentos;

/**
 * Las filas de un CSV.
 *
 * Dos cosas que en Colombia pasan siempre y rompen el lector ingenuo:
 *
 * 1. **El separador es el punto y coma.** Excel en configuración regional
 *    española lo exporta así, porque la coma es el separador decimal. Un lector
 *    que asuma la coma devuelve una sola columna por fila con todo dentro.
 * 2. **La codificación no es UTF-8.** Excel para Windows exporta en
 *    Windows-1252, y entonces «Instalación» llega con un byte suelto que MySQL
 *    rechaza al guardar el fragmento.
 */
class LectorCsv
{
    public static function filas(string $ruta, string $nombre): array
    {
        $contenido = (string) file_get_contents($ruta);

        // La BOM que pone Excel se cuela en el primer título de columna y
        // convierte «Plan» en «\u{FEFF}Plan», que luego no casa con nada.
        $contenido = preg_replace('/^\x{FEFF}/u', '', $contenido) ?? $contenido;

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $separador = self::separador($contenido);
        $filas = [];

        // Por un fichero temporal en vez de `explode("\n")`: un campo entre
        // comillas puede contener saltos de línea, y partir por líneas rompe
        // justo las direcciones y las descripciones largas.
        $puntero = fopen('php://temp', 'r+');
        fwrite($puntero, $contenido);
        rewind($puntero);

        while (($fila = fgetcsv($puntero, 0, $separador, '"', '\\')) !== false) {
            $filas[] = array_map(fn ($c) => (string) $c, $fila);
        }

        fclose($puntero);

        return FilasATrozos::de($filas, $nombre);
    }

    /**
     * Cuál de los dos separadores usa: el que más aparece en la primera línea.
     *
     * Se mira sólo la primera línea a propósito: es el encabezado, donde no hay
     * decimales que puedan contar como comas.
     */
    private static function separador(string $contenido): string
    {
        $primera = strtok($contenido, "\n") ?: '';

        return substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    }
}
