<?php

namespace App\Support\Sentimiento;

/**
 * El diccionario del semáforo: qué palabras mueven la aguja y cuánto.
 *
 * Es un léxico **propio, corto y de dominio**, y esa decisión va contra lo que
 * parece razonable. Los léxicos académicos de español —iSOL, ElhPolar, SEL—
 * traen miles de términos, pero son de polaridad *general*: están hechos para
 * reseñas de hoteles, no para soporte técnico. Medidos contra conversaciones
 * reales de atención al cliente, los métodos de léxico puro rinden alrededor
 * del 50 %, apenas mejor que tirar una moneda. Su licencia para uso comercial
 * tampoco está clara.
 *
 * Para un ISP colombiano, `tutela` y `SIC` valen más que cinco mil adjetivos:
 * un cliente que menciona la Superintendencia ya decidió escalar, y eso no lo
 * detecta ningún diccionario de polaridad genérica.
 *
 * ## Dos cosas que hay que entender antes de tocar estas listas
 *
 * **1. Tener un problema no es estar enfadado.** Todo el que escribe a soporte
 * tiene un problema. Si "no funciona", "falla" o "error" pesaran negativo, el
 * semáforo se pondría rojo entero el primer día y nadie volvería a mirarlo. Por
 * eso NO están aquí: describen la avería, no el ánimo de quien la reporta.
 *
 * **2. Lo caro es el falso negativo.** Un cliente furioso que se cuela en verde
 * cuesta mucho más que uno tranquilo marcado en amarillo. Ante la duda, pesa.
 *
 * ## Sobre el léxico colombiano
 *
 * `marica` y `parce` NO están en la lista de insultos, y es deliberado: en
 * Colombia funcionan como muletilla entre iguales ("no marica, es que el
 * internet...") mucho más que como agresión. Meterlas pintaría de rojo a media
 * Medellín escribiendo con toda normalidad. Si una empresa quiere tratarlas
 * como insulto, para eso está el campo `palabras_rojas` de sus ajustes.
 *
 * @see Analizador Quien aplica estas listas a un mensaje.
 */
class Lexico
{
    /**
     * Ya decidió escalar fuera de la empresa. Es la señal más fuerte que hay.
     *
     * No es enfado: es un hecho. Alguien que nombra la SIC no está desahogándose,
     * está avisando de lo que va a hacer, y eso no admite esperar en la cola.
     */
    public const ESCALADO = [
        'tutela', 'accion de tutela', 'sic', 'superintendencia', 'superservicios',
        'supertransporte', 'demanda', 'demandar', 'demandarlos', 'abogado',
        'defensoria', 'defensor del consumidor', 'procuraduria', 'fiscalia',
        'denuncia', 'denunciar', 'queja formal', 'derecho de peticion',
        'organismo de control', 'ministerio', 'consumidor financiero',
    ];

    public const PESO_ESCALADO = -0.95;

    /**
     * Se quiere ir. Distinto del enfado: uno se enfada y se queda; esto es fuga.
     *
     * Sin nombres de la competencia a propósito. "Claro", "Tigo" o "Movistar"
     * aparecen en frases perfectamente neutras —"vengo de Claro", "el técnico de
     * Tigo"— y en Colombia "claro" es además la palabra más común para decir que
     * sí. El coste del falso positivo ahí es altísimo.
     */
    public const FUGA = [
        'cancelar', 'cancelacion', 'cancelen', 'quiero cancelar', 'dar de baja',
        'darme de baja', 'retirar el servicio', 'retiro del servicio',
        'terminar el contrato', 'portabilidad', 'portar el numero', 'me cambio',
        'me voy para otro', 'otro operador', 'otro proveedor', 'la competencia',
        'no quiero seguir', 'no pienso seguir', 'ultima vez que',
    ];

    public const PESO_FUGA = -0.8;

    /**
     * Enfado explícito. El cliente está diciendo cómo se siente, no qué le pasa.
     */
    public const ENFADO = [
        'furioso', 'indignado', 'indignante', 'indignacion', 'harto', 'harta',
        'hartos', 'cansado de', 'cansada de', 'molesto', 'molesta', 'enojado',
        'enojada', 'bravo', 'brava', 'inaceptable', 'inadmisible', 'verguenza',
        'vergonzoso', 'pesimo', 'pesima', 'horrible', 'terrible', 'malisimo',
        'desastre', 'desastroso', 'estafa', 'estafadores', 'estafando',
        'ladrones', 'robo', 'robando', 'mentira', 'mentiras', 'mentirosos',
        'incompetentes', 'incompetencia', 'irresponsables', 'absurdo', 'ridiculo',
        'el colmo', 'ya basta', 'no mas', 'me tienen', 'burla', 'burlando',
        'falta de respeto', 'irrespeto', 'grosero', 'pesimo servicio',
    ];

    public const PESO_ENFADO = -0.7;

    /**
     * Insultos. Peso máximo porque no hay lectura amable posible.
     *
     * Lista corta y sin variantes creativas: no se trata de cazar todo lo que
     * alguien pueda escribir, sino de no perder al que insulta de frente. El
     * resto lo recoge la capa de IA, que sí entiende lo que no está escrito.
     */
    public const INSULTO = [
        'hp', 'hpta', 'hijueputa', 'malparido', 'malparidos', 'gonorrea',
        'estupidos', 'estupido', 'idiotas', 'idiota', 'imbeciles', 'imbecil',
        'basura', 'mierda', 'porqueria', 'pendejos', 'pendejada', 'chimba',
        'no joda', 'vayanse', 'metanse',
    ];

    public const PESO_INSULTO = -1.0;

    /**
     * Esfuerzo: lo tuvo que pedir más de una vez.
     *
     * Estas frases son la razón por la que el semáforo no es sólo un análisis de
     * sentimiento. En 70.000 conversaciones de soporte, *si al cliente lo
     * ayudaron* predijo su valoración mejor que el tono con el que escribía. "Es
     * la tercera vez que escribo" está redactado con toda educación y es de las
     * señales más fiables que existen.
     */
    public const ESFUERZO = [
        'segunda vez', 'tercera vez', 'cuarta vez', 'quinta vez', 'otra vez',
        'de nuevo', 'nuevamente', 'sigo esperando', 'sigo sin', 'sigue igual',
        'sigue sin', 'llevo dias', 'llevo horas', 'llevo semanas', 'llevo meses',
        'nadie responde', 'nadie me responde', 'nadie contesta', 'nadie me contesta',
        'no me han', 'no han venido', 'no han solucionado', 'todavia no',
        'aun no', 'ya les escribi', 'ya les dije', 'como les dije', 'ya reporte',
        'vuelvo a escribir', 'les repito', 'repito', 'sin respuesta',
        'me tienen esperando', 'cuanto mas', 'hasta cuando',
    ];

    public const PESO_ESFUERZO = -0.5;

    /**
     * Lo que baja la temperatura. Pesa menos que lo negativo, y es a propósito:
     * un "gracias" de cortesía no cancela tres mensajes de enfado.
     */
    public const POSITIVO = [
        'gracias', 'muchas gracias', 'mil gracias', 'agradezco', 'agradecido',
        'agradecida', 'excelente', 'perfecto', 'genial', 'buenisimo', 'amable',
        'amables', 'muy amable', 'muy bien', 'todo bien', 'quedo bien',
        'ya quedo', 'ya funciona', 'ya sirve', 'solucionado', 'resuelto',
        'me sirvio', 'funciono', 'felicitaciones', 'los felicito', 'buen servicio',
        'buena atencion', 'super', 'chevere', 'bacano', 'listo', 'de una',
        'bendiciones', 'que dios', 'feliz dia',
    ];

    public const PESO_POSITIVO = 0.6;

    /**
     * Invierten lo que venga detrás.
     *
     * Es donde más falla un léxico ingenuo en español: "no me sirvió" y "no
     * tengo ninguna queja" se leen igual de mal si nadie mira el `no`.
     */
    public const NEGADORES = [
        'no', 'nunca', 'jamas', 'sin', 'tampoco', 'ni', 'nada de', 'ningun',
        'ninguna', 'ningunos', 'ningunas',
    ];

    /**
     * Cuántas palabras hacia adelante alcanza un negador.
     *
     * Tres es el consenso habitual y encaja con el español: "no me sirvió"
     * (2), "no ha quedado bien" (3). Más allá empieza a invertir cosas que no
     * le tocaban.
     */
    public const ALCANCE_NEGACION = 3;

    /** Multiplican lo que venga detrás, sin cambiarle el signo. */
    public const INTENSIFICADORES = [
        'muy' => 1.4, 'super' => 1.4, 'demasiado' => 1.5, 'bastante' => 1.2,
        'tan' => 1.3, 're' => 1.3, 'completamente' => 1.5, 'totalmente' => 1.5,
        'absolutamente' => 1.5, 'nada' => 1.3, 'jamas' => 1.4,
    ];

    /**
     * Los emoji dicen lo que el texto calla, y en WhatsApp llegan solos en un
     * mensaje entero. Lista corta: los que aparecen de verdad en atención al
     * cliente, no el catálogo Unicode.
     *
     * 🙏 pesa poco a propósito: en Colombia es "gracias" tanto como "por favor
     * ayúdenme", y esa ambigüedad no se resuelve con un número.
     */
    public const EMOJI = [
        '😡' => -0.9, '🤬' => -1.0, '😠' => -0.8, '😤' => -0.6, '👎' => -0.7,
        '🙄' => -0.5, '😒' => -0.5, '😞' => -0.4, '😢' => -0.4, '😭' => -0.5,
        '💔' => -0.5, '⚠️' => -0.3, '❌' => -0.3,
        '😊' => 0.5, '😀' => 0.5, '😁' => 0.5, '🙂' => 0.3, '👍' => 0.6,
        '❤️' => 0.6, '🥰' => 0.6, '😍' => 0.6, '👏' => 0.6, '🎉' => 0.5,
        '💪' => 0.4, '✅' => 0.4, '🙏' => 0.2,
    ];

    /**
     * Las listas negativas con su peso, en el orden en que se buscan.
     *
     * El orden importa: se queda el primero que coincida para cada tramo de
     * texto, y los de más peso van delante para que "quiero cancelar, son unos
     * ladrones" cuente como fuga y no se diluya.
     *
     * @return array<string, array{list<string>, float}>
     */
    public static function categorias(): array
    {
        return [
            'insulto' => [self::INSULTO, self::PESO_INSULTO],
            'escalado' => [self::ESCALADO, self::PESO_ESCALADO],
            'fuga' => [self::FUGA, self::PESO_FUGA],
            'enfado' => [self::ENFADO, self::PESO_ENFADO],
            'esfuerzo' => [self::ESFUERZO, self::PESO_ESFUERZO],
            'positivo' => [self::POSITIVO, self::PESO_POSITIVO],
        ];
    }

    /** Cómo se llama cada categoría cuando hay que explicarle el color a alguien. */
    public const MOTIVOS = [
        'insulto' => 'lenguaje ofensivo',
        'escalado' => 'menciona una vía legal o un ente de control',
        'fuga' => 'habla de cancelar o irse',
        'enfado' => 'expresa enfado',
        'esfuerzo' => 'lo ha pedido más de una vez',
        'positivo' => 'agradece o confirma que quedó bien',
    ];
}
