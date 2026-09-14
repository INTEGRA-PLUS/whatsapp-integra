<?php

namespace App\Support\Sentimiento;

/**
 * Puntúa el texto de un mensaje. La capa 1 del semáforo, entera.
 *
 * Corre **dentro del webhook**, síncrona, así que tiene un presupuesto de
 * microsegundos: son búsquedas sobre un puñado de listas en memoria, sin base de
 * datos, sin red y sin estado. Si esto tardara, Meta reintentaría el webhook.
 *
 * No pretende acertar siempre. Pretende **no perderse a un cliente furioso**, que
 * es un objetivo distinto y más barato: un modelo con 88 % de acierto global que
 * se traga uno de cada cinco enfados es peor, para esto, que uno con 82 % que no
 * se traga ninguno. De ahí que ante el empate gane lo negativo.
 *
 * Lo que NO hace, y no es una carencia sino el límite del método: no entiende
 * ironía ni sarcasmo. "Excelente servicio, llevo tres días sin internet" le sale
 * positivo por el adjetivo y negativo por el esfuerzo, y acaba en tablas. Para
 * eso está la capa de IA.
 *
 * @see Lexico El diccionario.
 * @see Semaforo Quien convierte estas puntuaciones en un color.
 */
class Analizador
{
    /**
     * Cuánto sobrevive una palabra positiva cuando en el mismo mensaje hay algo
     * negativo.
     *
     * "Gracias, pero es la tercera vez que escribo" no es un mensaje agradecido:
     * el "gracias" es cortesía y el "tercera vez" es el contenido. Sin este
     * freno los dos se cancelan y el mensaje sale neutro, que es justo la
     * lectura equivocada.
     */
    private const FRENO_POSITIVO = 0.4;

    /**
     * Qué queda de una palabra negada.
     *
     * No se invierte el signo entero a propósito: "no es malo" no significa "es
     * bueno", significa "no es malo". Invertir del todo convertiría cualquier
     * descargo en un elogio.
     */
    private const INVERSION_NEGACION = -0.5;

    /** Peso de las palabras que añade la empresa en sus ajustes. */
    private const PESO_EMPRESA = -0.9;

    /**
     * @param list<string> $palabrasRojas Términos propios de la empresa.
     */
    public function mensaje(string $texto, array $palabrasRojas = []): Puntuacion
    {
        $crudo = trim($texto);

        if ($crudo === '') {
            return new Puntuacion(0.0);
        }

        $normal = $this->normalizar($crudo);
        $positivo = 0.0;
        $negativo = 0.0;
        $categorias = [];

        $listas = Lexico::categorias();

        if ($palabrasRojas !== []) {
            $listas['empresa'] = [$palabrasRojas, self::PESO_EMPRESA];
        }

        foreach ($listas as $categoria => [$terminos, $peso]) {
            $aporte = $this->buscar($normal, $terminos, $peso);

            if ($aporte === 0.0) {
                continue;
            }

            // Sólo se apunta la categoría si el término conservó su signo: un
            // "no me sirvió" puntúa negativo por la negación de "sirvió", y
            // apuntarlo como «positivo» daría un motivo que dice lo contrario
            // de lo que el número acaba de decidir.
            if (($aporte > 0) === ($peso > 0)) {
                $categorias[] = $categoria;
            }

            if ($aporte > 0) {
                $positivo += $aporte;
            } else {
                $negativo += $aporte;
            }
        }

        // Los emoji se leen sobre el texto crudo: normalizar no los toca, pero
        // buscarlos aquí deja claro que van por su cuenta y no pasan por
        // negación ni por intensificadores. Un 😡 detrás de un "no" sigue
        // siendo un 😡.
        $espera = $this->duracion($normal);

        if ($espera !== 0.0) {
            $negativo += $espera;
            $categorias[] = 'esfuerzo';
        }

        $emoji = $this->emoji($crudo);

        if ($emoji > 0) {
            $positivo += $emoji;
        } else {
            $negativo += $emoji;
        }

        if ($emoji !== 0.0) {
            $categorias[] = 'emoji';
        }

        // Mayúsculas sostenidas y signos repetidos MULTIPLICAN lo negativo, no
        // lo crean. Es una decisión de dominio, no un atajo: mucha gente mayor
        // escribe entera en mayúsculas por costumbre, y en Colombia son una
        // parte nada pequeña de quien escribe a un ISP. Tratarlas como enfado
        // pintaría de rojo a clientes que sólo están escribiendo como siempre.
        if ($negativo < 0) {
            $negativo *= $this->enfasis($crudo);
        }

        if ($negativo < 0) {
            $positivo *= self::FRENO_POSITIVO;
        }

        return new Puntuacion(
            max(-1.0, min(1.0, $positivo + $negativo)),
            array_values(array_unique($categorias))
        );
    }

    /**
     * Busca los términos de una lista y devuelve lo que suman.
     *
     * Cada término cuenta **una vez** aunque se repita: quien escribe "ladrones
     * ladrones ladrones" no está tres veces más enfadado, y sin este tope una
     * sola palabra repetida saturaría la escala.
     *
     * @param list<string> $terminos
     */
    private function buscar(string $texto, array $terminos, float $peso): float
    {
        $aportes = [];

        foreach ($terminos as $termino) {
            $termino = $this->normalizar((string) $termino);

            if ($termino === '') {
                continue;
            }

            $posicion = $this->posicion($texto, $termino);

            if ($posicion === null) {
                continue;
            }

            $antes = substr($texto, 0, $posicion);
            $aporte = $peso * $this->intensidad($antes);

            if ($this->negado($antes)) {
                $aporte *= self::INVERSION_NEGACION;
            }

            $aportes[] = $aporte;
        }

        if ($aportes === []) {
            return 0.0;
        }

        // Manda el término más fuerte y los demás suman un cuarto, hasta dos.
        //
        // Sumarlos todos saturaba la escala con nada: "gracias, ya quedó, todo
        // bien" se iba al tope igual que un insulto, y entonces el número deja
        // de servir para ordenar ni para suavizar. Repetir una palabra tampoco
        // multiplica el ánimo: quien escribe "ladrones ladrones ladrones" no
        // está tres veces más enfadado.
        usort($aportes, fn ($a, $b) => abs($b) <=> abs($a));

        return $aportes[0] + array_sum(
            array_map(fn ($extra) => $extra * 0.25, array_slice($aportes, 1, 2))
        );
    }

    /**
     * "Llevo tres días sin internet" es fricción, y no la dice ninguna palabra
     * del léxico.
     *
     * Sólo cuenta a partir de dos unidades: "hace un día" es un reporte normal.
     * El tiempo que el cliente dice llevar esperando no es el mismo que el que
     * lleva de verdad —ese lo sabe Semaforo mirando los mensajes—, pero lo que
     * se mide aquí es otra cosa: cuánto cree él que lleva, que es lo que
     * determina con qué ánimo escribe.
     */
    private function duracion(string $texto): float
    {
        $numeros = '\\d+|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|varios|varias|muchos|muchas';

        return preg_match('/\\b(?:'.$numeros.')\\s+(?:dias|semanas|meses)\\b/u', $texto)
            ? Lexico::PESO_ESFUERZO
            : 0.0;
    }

    /**
     * Dónde empieza el término, o null.
     *
     * Con frontera de palabra para que "sic" no salte dentro de "básico" ni
     * "robo" dentro de "robótica". Es el mismo problema que resuelve el modo
     * «palabra completa» del enrutado por palabra clave, y por la misma razón.
     */
    private function posicion(string $texto, string $termino): ?int
    {
        $patron = '/(?<![a-z0-9])'.preg_quote($termino, '/').'(?![a-z0-9])/u';

        if (! preg_match($patron, $texto, $coincidencia, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return (int) $coincidencia[0][1];
    }

    /** ¿Hay un negador en las últimas palabras antes de esto? */
    private function negado(string $antes): bool
    {
        // La negación no cruza puntuación, y sin esto el método se equivoca en
        // el caso más corriente: en "no tengo ninguna queja, todo excelente" el
        // `ninguna` niega a `queja`, no a `excelente` — están en frases
        // distintas. Sin el corte, un cliente contento salía negativo.
        $frase = preg_split('/[,.;:!?¡¿\\n]/u', $antes) ?: [$antes];
        $antes = (string) end($frase);

        $previas = array_slice($this->palabras($antes), -Lexico::ALCANCE_NEGACION);

        foreach (Lexico::NEGADORES as $negador) {
            // Los negadores de una palabra se comparan con el tramo; los de dos
            // ("nada de") se buscan en el texto de esas mismas palabras.
            if (str_contains($negador, ' ')) {
                if (str_ends_with(trim($antes), $negador)) {
                    return true;
                }

                continue;
            }

            if (in_array($negador, $previas, true)) {
                return true;
            }
        }

        return false;
    }

    /** El multiplicador del intensificador que preceda, si lo hay. */
    private function intensidad(string $antes): float
    {
        $previa = $this->palabras($antes);
        $ultima = end($previa);

        return is_string($ultima)
            ? (Lexico::INTENSIFICADORES[$ultima] ?? 1.0)
            : 1.0;
    }

    /** Lo que suman los emoji del mensaje. Cada uno cuenta una vez. */
    private function emoji(string $crudo): float
    {
        $total = 0.0;

        foreach (Lexico::EMOJI as $icono => $peso) {
            if (str_contains($crudo, $icono)) {
                $total += $peso;
            }
        }

        return max(-1.0, min(1.0, $total));
    }

    /**
     * Cuánto sube el tono el énfasis: mayúsculas sostenidas y signos repetidos.
     *
     * El umbral de 12 caracteres evita que un "OK" o unas siglas ("PQR", "SIC")
     * cuenten como gritar.
     */
    private function enfasis(string $crudo): float
    {
        $factor = 1.0;
        $letras = preg_replace('/[^\p{L}]/u', '', $crudo) ?? '';

        if (mb_strlen($letras) >= 12) {
            $mayusculas = preg_replace('/[^\p{Lu}]/u', '', $letras) ?? '';

            if (mb_strlen($mayusculas) / mb_strlen($letras) > 0.7) {
                $factor += 0.3;
            }
        }

        if (preg_match('/[!?¡¿]{3,}/u', $crudo)) {
            $factor += 0.2;
        }

        return $factor;
    }

    /** @return list<string> */
    private function palabras(string $texto): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $texto) ?: []));
    }

    /**
     * Minúsculas y sin tildes.
     *
     * Media Colombia escribe "garantia" y la otra media "garantía". Es el mismo
     * `normalizar()` de KeywordRoutingExtension, duplicado a propósito: aquél
     * pertenece a una extensión y éste a un servicio que quiero poder usar sin
     * cargar con ella.
     */
    private function normalizar(string $texto): string
    {
        return strtr(mb_strtolower(trim($texto)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n',
        ]);
    }
}
