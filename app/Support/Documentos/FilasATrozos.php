<?php

namespace App\Support\Documentos;

/**
 * Convierte las filas de una hoja en trozos que se explican solos.
 *
 * **Cada fila es un trozo, y lleva el encabezado pegado.** Una fila de tarifario
 * no se guarda como `300 megas | 89900 | 50000` sino como:
 *
 * ```
 * Plan: 300 megas · Precio mensual: $89.900 · Instalación: $50.000
 * ```
 *
 * Es la diferencia entre poder responder «¿cuánto cuesta el de 300 megas?» y
 * devolverle al modelo un «89900» suelto que no sabe de qué es. Partir la hoja
 * en bloques de 800 caracteres, como se hace con la prosa, deja filas huérfanas
 * de su encabezado justo en cada corte.
 */
class FilasATrozos
{
    /**
     * Hasta dónde se busca el encabezado.
     *
     * Muchas hojas de verdad empiezan con el logo, un título y un par de filas
     * en blanco. Cinco filas cubren eso sin llegar a confundir datos con
     * encabezado en una hoja que empiece directamente.
     */
    private const FILAS_DE_CORTESIA = 5;

    /**
     * @param  list<list<string>>  $filas
     * @return list<array{texto: string, origen: ?string}>
     */
    public static function de(array $filas, string $nombre, ?string $hoja = null): array
    {
        $indice = self::dondeEstaElEncabezado($filas);

        if ($indice === null) {
            return [];
        }

        $encabezado = self::normalizar($filas[$indice]);
        $fuente = $hoja ? "{$nombre} · hoja «{$hoja}»" : $nombre;
        $trozos = [];

        foreach (array_slice($filas, $indice + 1) as $i => $fila) {
            $partes = [];

            foreach ($fila as $columna => $valor) {
                $valor = trim((string) $valor);

                // Las celdas vacías no se escriben. «Permanencia:» sin nada
                // detrás no informa de nada y se paga como tokens en cada
                // consulta que devuelva esta fila.
                if ($valor === '') {
                    continue;
                }

                $titulo = $encabezado[$columna] ?? null;
                $partes[] = $titulo ? "{$titulo}: {$valor}" : $valor;
            }

            if ($partes === []) {
                continue;
            }

            $trozos[] = [
                'texto' => implode(' · ', $partes),
                // La fila que se nombra es la del Excel, contando el encabezado
                // y las filas de cortesía: es la que el admin ve al abrirlo, y
                // la única con la que puede ir a comprobarlo.
                'origen' => $fuente.' · fila '.($indice + $i + 2),
            ];
        }

        return $trozos;
    }

    /**
     * La primera fila con al menos dos celdas con texto.
     *
     * Con dos y no con una porque un título suelto («TARIFARIO 2026») ocupa una
     * sola celda y se tomaría por encabezado, dejando todas las filas de datos
     * etiquetadas con el título del documento.
     */
    private static function dondeEstaElEncabezado(array $filas): ?int
    {
        foreach (array_slice($filas, 0, self::FILAS_DE_CORTESIA) as $i => $fila) {
            $conTexto = array_filter($fila, fn ($c) => trim((string) $c) !== '');

            if (count($conTexto) >= 2) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Los títulos de columna, ya limpios.
     *
     * @return array<int, string>
     */
    private static function normalizar(array $fila): array
    {
        $titulos = [];

        foreach ($fila as $i => $celda) {
            $titulo = trim(preg_replace('/\s+/u', ' ', (string) $celda) ?? '');

            if ($titulo !== '') {
                $titulos[$i] = mb_substr($titulo, 0, 60);
            }
        }

        return $titulos;
    }
}
