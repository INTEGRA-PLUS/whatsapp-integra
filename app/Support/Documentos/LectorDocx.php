<?php

namespace App\Support\Documentos;

use ZipArchive;

/**
 * El texto de un .docx, sin dependencias.
 *
 * Un DOCX es un zip con `word/document.xml` dentro, así que basta con `zip` y
 * `dom`, que ya están en la imagen.
 *
 * Lo único con truco es que el texto de un párrafo viene troceado en varios
 * `<w:t>` —Word abre uno nuevo cada vez que cambia algo del formato, incluso
 * una palabra en negrita en mitad de la frase— así que hay que pegarlos **sin
 * espacio entre ellos** y poner el salto de línea por párrafo (`<w:p>`), no por
 * fragmento. Al revés sale «pre cio» donde el documento decía «precio».
 */
class LectorDocx
{
    public static function texto(string $ruta): string
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            throw new DocumentoIlegible('El archivo de Word está dañado y no se pudo abrir.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new DocumentoIlegible('El archivo no parece un documento de Word válido.');
        }

        return self::delXml($xml);
    }

    private static function delXml(string $xml): string
    {
        $doc = new \DOMDocument;

        // Word mete namespaces y entidades que a libxml no le gustan; los avisos
        // no aportan nada y ensucian el log del worker.
        $previo = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        if (! $ok) {
            throw new DocumentoIlegible('El contenido del documento de Word no se pudo interpretar.');
        }

        $parrafos = [];

        foreach ($doc->getElementsByTagName('p') as $p) {
            $parrafo = trim(self::recorrer($p));

            if ($parrafo !== '') {
                $parrafos[] = $parrafo;
            }
        }

        return implode("\n", $parrafos);
    }

    /**
     * Recorre un párrafo en el orden en que está escrito.
     *
     * Hay que recorrer y no recoger todos los `<w:t>` de golpe porque el orden
     * importa: un `<w:tab/>` entre dos textos separa las columnas de una tabla,
     * y si los tabuladores se añaden al final —o no se añaden— «Plan 300
     * megas» y «$89.900» llegan pegados y el modelo lee un precio que no
     * existe.
     */
    private static function recorrer(\DOMNode $nodo): string
    {
        $texto = '';

        foreach ($nodo->childNodes as $hijo) {
            if (! $hijo instanceof \DOMElement) {
                continue;
            }

            $texto .= match ($hijo->localName) {
                't' => $hijo->textContent,
                'tab' => ' ',
                'br', 'cr' => "\n",
                default => self::recorrer($hijo),
            };
        }

        return $texto;
    }
}
