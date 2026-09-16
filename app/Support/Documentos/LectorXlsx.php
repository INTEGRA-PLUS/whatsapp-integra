<?php

namespace App\Support\Documentos;

use ZipArchive;

/**
 * Las filas de un .xlsx, hoja por hoja y sin dependencias.
 *
 * Un XLSX es un zip de XML, así que `zip` y `SimpleXML` —ya en la imagen—
 * bastan. Lo que tiene truco no es abrirlo: son cuatro detalles del formato que
 * se olvidan y que se notan en la respuesta que lee un cliente.
 *
 * 1. **Las cadenas no están en la hoja.** Están en `xl/sharedStrings.xml` y la
 *    celda guarda su número (`t="s"`). Sin resolverlo, el tarifario entero sale
 *    como una lista de índices.
 * 2. **Las fechas son números.** Un `45678` es una fecha y sólo se distingue
 *    mirando el formato de la celda en `xl/styles.xml`. Sin eso, la vigencia de
 *    un tarifario empieza «el 45678».
 * 3. **Las fórmulas guardan dos cosas**, la fórmula (`<f>`) y el último valor
 *    calculado (`<v>`). Hay que leer el valor: al modelo no le sirve
 *    `=B2*1.19`.
 * 4. **Las celdas vacías no ocupan sitio.** La fila salta de `A5` a `D5`, así
 *    que hay que colocar cada celda por su letra de columna o los valores se
 *    corren y quedan bajo el título equivocado.
 */
class LectorXlsx
{
    /** Los formatos de fecha que Excel trae de fábrica. */
    private const FORMATOS_DE_FECHA = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    public static function filas(string $ruta, string $nombre): array
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            throw new DocumentoIlegible('El archivo de Excel está dañado y no se pudo abrir.');
        }

        try {
            $cadenas = self::cadenasCompartidas($zip);
            $sonFecha = self::estilosDeFecha($zip);
            $trozos = [];

            foreach (self::hojas($zip) as $hoja => $interna) {
                $xml = $zip->getFromName($interna);

                if ($xml === false) {
                    continue;
                }

                $filas = self::filasDeLaHoja($xml, $cadenas, $sonFecha);
                $trozos = array_merge($trozos, FilasATrozos::de($filas, $nombre, $hoja));

                if (count($trozos) >= ExtraerTexto::MAXIMO_TROZOS) {
                    break;
                }
            }

            return $trozos;
        } finally {
            $zip->close();
        }
    }

    /**
     * Las hojas, con su nombre visible.
     *
     * El nombre importa: en un tarifario suele ser lo que distingue «hogar» de
     * «empresas», y va en la cita para que la IA pueda decir de cuál lo sacó.
     *
     * @return array<string, string> Nombre visible => ruta dentro del zip.
     */
    private static function hojas(ZipArchive $zip): array
    {
        $libro = self::xml($zip->getFromName('xl/workbook.xml'));
        $relaciones = self::relaciones($zip);
        $hojas = [];

        if ($libro === null) {
            return ['' => 'xl/worksheets/sheet1.xml'];
        }

        foreach ($libro->sheets->sheet ?? [] as $hoja) {
            $nombre = (string) $hoja['name'];
            $id = (string) $hoja->attributes('r', true)['id'];
            $destino = $relaciones[$id] ?? null;

            if ($destino !== null) {
                $hojas[$nombre] = $destino;
            }
        }

        return $hojas ?: ['' => 'xl/worksheets/sheet1.xml'];
    }

    /** @return array<string, string> */
    private static function relaciones(ZipArchive $zip): array
    {
        $xml = self::xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $relaciones = [];

        foreach ($xml->Relationship ?? [] as $r) {
            $destino = ltrim((string) $r['Target'], '/');

            // El destino es relativo a `xl/`, salvo cuando ya viene absoluto
            // desde la raíz del paquete.
            $relaciones[(string) $r['Id']] = str_starts_with($destino, 'xl/')
                ? $destino
                : 'xl/'.$destino;
        }

        return $relaciones;
    }

    /** @return list<string> */
    private static function cadenasCompartidas(ZipArchive $zip): array
    {
        $xml = self::xml($zip->getFromName('xl/sharedStrings.xml'));
        $cadenas = [];

        foreach ($xml->si ?? [] as $si) {
            // Una cadena con formato mixto viene partida en varios `<r><t>`,
            // igual que en Word. Se pegan sin separador.
            $cadenas[] = isset($si->t)
                ? (string) $si->t
                : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r ?? [])));
        }

        return $cadenas;
    }

    /**
     * Qué índices de estilo son fechas.
     *
     * @return array<int, bool>
     */
    private static function estilosDeFecha(ZipArchive $zip): array
    {
        $xml = self::xml($zip->getFromName('xl/styles.xml'));

        if ($xml === null) {
            return [];
        }

        // Los formatos que el usuario define a mano («dd/mm/yyyy») llevan id
        // 164 o mayor y sólo se reconocen por su código, no por una lista.
        $personalizados = [];

        foreach ($xml->numFmts->numFmt ?? [] as $formato) {
            $codigo = (string) $formato['formatCode'];

            if (preg_match('/[ymdhs]/i', preg_replace('/\[[^\]]*\]|"[^"]*"/', '', $codigo) ?? '')) {
                $personalizados[(int) $formato['numFmtId']] = true;
            }
        }

        $estilos = [];
        $i = 0;

        // Con un contador y no con la clave del `foreach`: sobre SimpleXML la
        // clave es el nombre del elemento —siempre `xf`— y no su posición, que
        // es justo lo que la celda guarda en su atributo `s`. Con la clave, la
        // tabla de estilos queda con una sola entrada y ninguna fecha se
        // reconoce.
        foreach ($xml->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            $estilos[$i++] = in_array($id, self::FORMATOS_DE_FECHA, true) || isset($personalizados[$id]);
        }

        return $estilos;
    }

    /** @return list<list<string>> */
    private static function filasDeLaHoja(string $xml, array $cadenas, array $sonFecha): array
    {
        $hoja = self::xml($xml);
        $filas = [];

        foreach ($hoja->sheetData->row ?? [] as $fila) {
            $celdas = [];

            foreach ($fila->c ?? [] as $celda) {
                $columna = self::columna((string) $celda['r']);
                $celdas[$columna] = self::valor($celda, $cadenas, $sonFecha);
            }

            if ($celdas === []) {
                $filas[] = [];

                continue;
            }

            // Se rellenan los huecos para que cada valor caiga bajo su título:
            // una fila que salta de A a D tiene que dejar B y C vacías, no
            // correr D hasta la posición de B.
            $filas[] = array_map(
                fn ($i) => $celdas[$i] ?? '',
                range(0, max(array_keys($celdas)))
            );
        }

        return $filas;
    }

    private static function valor(\SimpleXMLElement $celda, array $cadenas, array $sonFecha): string
    {
        $tipo = (string) $celda['t'];

        if ($tipo === 's') {
            return $cadenas[(int) $celda->v] ?? '';
        }

        if ($tipo === 'inlineStr') {
            return (string) ($celda->is->t ?? '');
        }

        if ($tipo === 'b') {
            return ((string) $celda->v) === '1' ? 'sí' : 'no';
        }

        // `<v>` y no `<f>`: al modelo no le sirve «=B2*1.19».
        $valor = (string) ($celda->v ?? '');

        if ($valor === '' || $tipo === 'str' || $tipo === 'e') {
            return $valor;
        }

        $estilo = isset($celda['s']) ? (int) $celda['s'] : null;

        if ($estilo !== null && ($sonFecha[$estilo] ?? false) && is_numeric($valor)) {
            return self::fecha((float) $valor);
        }

        return $valor;
    }

    /**
     * El número de serie de Excel, en fecha legible.
     *
     * La época es el 30-dic-1899 y no el 1-ene-1900: Excel arrastra desde Lotus
     * 1-2-3 un 1900 bisiesto que no existió, y descontar ese día de más es lo
     * que hace que las fechas cuadren.
     */
    private static function fecha(float $serie): string
    {
        if ($serie <= 0) {
            return (string) $serie;
        }

        $fecha = (new \DateTimeImmutable('1899-12-30'))->modify('+'.(int) $serie.' days');
        $hora = $serie - floor($serie);

        return $hora > 0
            ? $fecha->modify('+'.(int) round($hora * 86400).' seconds')->format('d/m/Y H:i')
            : $fecha->format('d/m/Y');
    }

    /** `C5` → 2. La letra da la columna; el número de fila aquí no importa. */
    private static function columna(string $referencia): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($referencia), $coincide);

        $letras = $coincide[1] ?? 'A';
        $indice = 0;

        foreach (str_split($letras) as $letra) {
            $indice = $indice * 26 + (ord($letra) - 64);
        }

        return $indice - 1;
    }

    private static function xml(string|false $contenido): ?\SimpleXMLElement
    {
        if ($contenido === false || $contenido === '') {
            return null;
        }

        $previo = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contenido, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        return $xml ?: null;
    }
}
