<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyIntegration;

/**
 * Quién es la IA de una empresa: nombre, tono, conocimiento y límites.
 *
 * Existe porque el flujo de n8n es uno solo para toda la plataforma y traía la
 * identidad escrita en el prompt: todas las empresas se presentaban como el
 * asistente de Integra, incluidas las que no usan Integra. La identidad baja
 * ahora en el payload y el flujo la arma; lo que queda escrito allí son las
 * reglas de plataforma, que ninguna empresa puede relajar.
 *
 * Un solo sitio para los dos flujos —menús y chats— a propósito: si cada
 * cliente HTTP armara su bloque, el cliente hablaría con Sofía y de repente le
 * contestaría otro con otro tono. Lo único que cambia entre los dos es
 * `puede_ejecutar`, que no es de la empresa sino del flujo: describe si al otro
 * lado hay herramientas.
 *
 * El saneo vive aquí y no sólo en la validación del panel porque estas filas
 * también se escriben desde tinker, desde una migración o desde un seeder, y
 * el texto acaba dentro de un prompt: la última línea de defensa tiene que
 * estar en el camino de salida, no en el formulario.
 */
class AiAssistantProfile
{
    /** Tratamientos que el flujo sabe aplicar. */
    public const TREATMENTS = ['tu', 'usted'];

    public const MAX_NAME = 40;
    public const MAX_TONE = 120;
    public const MAX_KNOWLEDGE = 4000;
    public const MAX_LIMITS = 10;
    public const MAX_LIMIT = 200;

    /**
     * Con qué se atiende a una empresa que no configuró nada.
     *
     * El nombre se deja vacío a propósito: el flujo cae entonces en "el
     * asistente virtual de <empresa>", que es genérico pero cierto. Rellenarlo
     * aquí con algo inventado sería ponerle a cuarenta empresas el mismo
     * nombre de fantasía.
     */
    public const DEFAULTS = [
        'nombre_asistente' => '',
        'tratamiento' => 'tu',
        'tono' => 'cordial, claro y profesional',
        'conocimiento' => '',
        'limites' => [],
    ];

    /**
     * El bloque `asistente` que espera el flujo de n8n.
     *
     * @param bool $canExecute ¿El flujo que va a recibir esto tiene
     *                         herramientas? El de menús consulta Integra y
     *                         radica; el de chats sólo conversa. De ello
     *                         depende que el prompt le permita confirmar
     *                         acciones: prometerlas sin herramienta es peor
     *                         que no ofrecerlas.
     */
    public static function payload(int $companyId, bool $canExecute): array
    {
        return self::settings($companyId) + [
            // El nombre de la empresa NO se guarda en settings: se lee de
            // `companies` en cada envío. Guardarlo sería una copia que un día
            // se queda vieja y deja al asistente presentándose con el nombre
            // anterior de la empresa.
            'empresa' => (string) (Company::where('id', $companyId)->value('name') ?? ''),
            'puede_ejecutar' => $canExecute,
        ];
    }

    /**
     * Cómo se va a presentar, para enseñárselo al admin en el panel.
     *
     * OJO: esto duplica la función `identidad()` del nodo de n8n, y es la única
     * duplicación que me permito aquí. La alternativa era mandar la frase ya
     * armada en el payload, pero entonces el prompt no podría usarla en dos
     * sitios distintos con dos formas distintas ("Eres X" y "responde que eres
     * X"), y el flujo quedaría atado a la redacción que decidiera PHP.
     *
     * Son cuatro casos y no cambian a menudo. Si se toca uno, hay que tocar el
     * otro: lo que se rompe si no es el texto de la vista previa, no el del
     * cliente —el prompt manda—, así que el fallo es visible y barato.
     */
    public static function presentation(int $companyId): string
    {
        $p = self::payload($companyId, false);
        $name = $p['nombre_asistente'];
        $company = $p['empresa'];

        return match (true) {
            $name !== '' && $company !== '' => "{$name}, el asistente virtual de {$company}",
            $name !== '' => $name,
            $company !== '' => "el asistente virtual de {$company}",
            default => 'el asistente virtual de esta empresa',
        };
    }

    /** Los ajustes guardados de una empresa, ya saneados y completos. */
    public static function settings(int $companyId): array
    {
        $stored = CompanyIntegration::where('company_id', $companyId)
            ->where('key', CompanyIntegration::KEY_AI_ASSISTANT)
            ->value('settings');

        return self::sanitize(is_array($stored) ? $stored : []);
    }

    /** Guarda los ajustes de una empresa, saneados. Devuelve lo que quedó. */
    public static function save(int $companyId, array $input): array
    {
        $clean = self::sanitize($input);

        CompanyIntegration::updateOrCreate(
            ['company_id' => $companyId, 'key' => CompanyIntegration::KEY_AI_ASSISTANT],
            ['settings' => $clean]
        );

        return $clean;
    }

    /**
     * Deja los ajustes en la forma exacta que el flujo espera.
     *
     * Rellena lo que falte y descarta lo que no reconoce: una clave de más en
     * la fila no puede convertirse en una línea de más en el prompt.
     */
    public static function sanitize(array $input): array
    {
        $limits = is_array($input['limites'] ?? null) ? $input['limites'] : [];

        return [
            'nombre_asistente' => self::line($input['nombre_asistente'] ?? '', self::MAX_NAME),
            'tratamiento' => in_array($input['tratamiento'] ?? null, self::TREATMENTS, true)
                ? $input['tratamiento']
                : self::DEFAULTS['tratamiento'],
            'tono' => self::line($input['tono'] ?? '', self::MAX_TONE) ?: self::DEFAULTS['tono'],
            'conocimiento' => self::text($input['conocimiento'] ?? '', self::MAX_KNOWLEDGE),
            'limites' => collect($limits)
                ->map(fn ($l) => self::line(is_scalar($l) ? (string) $l : '', self::MAX_LIMIT))
                ->filter()
                ->unique()
                ->take(self::MAX_LIMITS)
                ->values()
                ->all(),
        ];
    }

    /**
     * Un campo de una sola línea.
     *
     * Los saltos se convierten en espacios en vez de rechazarse: quien pega el
     * nombre desde otro sitio se trae un salto sin querer, y fallarle por eso
     * sería incomprensible. Lo que no puede pasar es que ese salto abra una
     * línea nueva dentro del prompt, que es donde una frase suelta pasa por
     * regla del sistema.
     */
    private static function line(string|int|float $value, int $max): string
    {
        return self::text(preg_replace('/\s+/u', ' ', (string) $value) ?? '', $max);
    }

    /**
     * Texto libre listo para entrar en un prompt.
     *
     * Dos cosas, y las dos importan:
     *
     * 1. Fuera los caracteres de control. Un `\r` o un carácter invisible
     *    dentro del texto es la forma clásica de colar algo que el admin no ve
     *    en el formulario pero el modelo sí lee.
     *
     * 2. Las rayas largas se cortan. El flujo mete este texto dentro de un
     *    bloque delimitado por `--- INFORMACIÓN DE LA EMPRESA ---` y le dice al
     *    modelo que ahí dentro hay datos, no órdenes. Quien escriba ese
     *    delimitador dentro del texto se sale del bloque y todo lo que ponga
     *    después vuelve a leerse como instrucción: es la inyección de prompt
     *    que este método existe para cerrar.
     *
     * Las URLs sí se dejan pasar. "Nuestra web es tal" es lo primero que
     * cualquier empresa quiere poner ahí, y quitarlas no protegería de nada
     * nuevo: el admin que quisiera mandarle un enlace a sus propios clientes
     * ya puede hacerlo desde el texto de una opción del menú.
     */
    private static function text(string $value, int $max): string
    {
        $clean = preg_replace('/[^\P{C}\n]/u', '', $value) ?? $value;
        $clean = preg_replace('/-{3,}/', '-', $clean) ?? $clean;

        return trim(mb_substr($clean, 0, $max));
    }
}
