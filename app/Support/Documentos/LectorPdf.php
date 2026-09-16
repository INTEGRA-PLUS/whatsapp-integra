<?php

namespace App\Support\Documentos;

use Smalot\PdfParser\Parser;

/**
 * El texto de un PDF, página a página.
 *
 * `smalot/pdfparser` es PHP puro y por eso se eligió: meter `pdftotext` o
 * LibreOffice en la imagen añade un binario más que mantener para leer unos
 * pocos ficheros al mes.
 *
 * **Lo que no hace, y hay que contar al admin: el PDF escaneado.** Muchos
 * reglamentos son una foto del papel. Desde fuera son idénticos a un PDF
 * normal, pero no tienen capa de texto y aquí salen con cero caracteres. Eso no
 * se arregla con otra librería —hace falta OCR, que es otro proyecto— así que lo
 * que toca es detectarlo y decirlo con esas palabras.
 */
class LectorPdf
{
    /**
     * @return array<int, string> Página (empezando en 1) => su texto.
     */
    public static function paginas(string $ruta): array
    {
        try {
            $pdf = (new Parser)->parseFile($ruta);
        } catch (\Throwable $e) {
            // El mensaje de la librería es para un desarrollador («Secured pdf
            // file», «Invalid object reference»); el admin necesita saber qué
            // hacer, no qué falló.
            throw new DocumentoIlegible(
                'El PDF no se pudo abrir. Puede estar dañado o protegido con contraseña.'
            );
        }

        $texto = [];

        foreach ($pdf->getPages() as $indice => $pagina) {
            try {
                $texto[$indice + 1] = (string) $pagina->getText();
            } catch (\Throwable $e) {
                // Una página ilegible —una fuente rara, un objeto roto— no puede
                // tumbar las otras doscientas. Se salta y el documento entra
                // con lo que sí se pudo leer.
                $texto[$indice + 1] = '';
            }
        }

        return $texto;
    }
}
