<?php

namespace App\Extensions;

/**
 * El manifiesto de una extensión del catálogo.
 *
 * Métodos y no un array de configuración porque las opciones de los ajustes
 * dependen de datos de la empresa —sus etiquetas, sus agentes— y un array
 * estático no sabe resolverlos. Además sanitizeSettings() necesita consultar la
 * base de datos para comprobar que lo que llega del formulario es de la empresa
 * que lo manda, y eso no cabe en una constante.
 *
 * Los ganchos NO están aquí: son interfaces sueltas (App\Extensions\Contracts)
 * que cada extensión implementa sólo si las usa. Si estuvieran en esta clase,
 * toda extensión cargaría con tres métodos vacíos y el runner no podría saber
 * cuáles hacen algo de verdad.
 *
 * Para añadir una extensión: se escribe la clase y se registra en
 * config/extensions.php. Nada más — ni migración, ni ruta, ni pantalla.
 */
abstract class Extension
{
    public const CATEGORIA_CONVERSACIONES = 'conversaciones';

    public const CATEGORIA_PRODUCTIVIDAD = 'productividad';

    public const CATEGORIA_AUTOMATIZACION = 'automatizacion';

    /**
     * Clave estable de la extensión. Es lo que se guarda en
     * `company_extensions.slug`, así que cambiarla desinstala la extensión de
     * todas las empresas que la tuvieran.
     */
    abstract public function slug(): string;

    abstract public function name(): string;

    /** Una línea: lo que se lee en la tarjeta del catálogo. */
    abstract public function description(): string;

    /**
     * El texto largo del detalle: qué hace, cuándo se dispara y —sobre todo—
     * qué NO hace. La mitad de las dudas de una extensión son sobre el límite.
     */
    abstract public function detail(): string;

    /** Nombre de un icono de lucide-react. El frontend lo resuelve por mapa. */
    abstract public function icon(): string;

    abstract public function category(): string;

    /**
     * Los permisos de la plataforma que la extensión necesita para trabajar.
     *
     * No se comprueban al instalar: se muestran en el detalle antes de
     * instalar, como los permisos de una extensión del navegador, para que
     * quien instala sepa a qué le está dando acceso.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * Dónde se engancha, en cristiano, para la ficha de detalle.
     *
     * @return list<string>
     */
    public function hooks(): array
    {
        return [];
    }

    /**
     * Los campos configurables, en forma declarativa para que el frontend los
     * pinte sin saber nada de esta extensión en concreto.
     *
     * Cada campo: key, type (text|textarea|number|boolean|select|multiselect|rules),
     * label, help y lo propio del tipo (min/max, options, source).
     *
     * `source` ('tags' | 'agents') deja que el controlador rellene las opciones
     * con datos de la empresa que mira. Es lo que permite que una extensión
     * nueva que necesite elegir una etiqueta no toque ni una línea de React.
     *
     * @return list<array<string, mixed>>
     */
    public function settingsSchema(): array
    {
        return [];
    }

    /**
     * Con qué ajustes nace al instalarse.
     *
     * @return array<string, mixed>
     */
    public function defaultSettings(): array
    {
        $defaults = [];

        foreach ($this->settingsSchema() as $field) {
            $defaults[$field['key']] = $field['default'] ?? null;
        }

        return $defaults;
    }

    /**
     * Valida y limpia lo que llega del formulario.
     *
     * Aquí es donde se comprueba que cada etiqueta y cada agente referenciados
     * sean de `$companyId`. El `<select>` sólo ofrecía los suyos, pero el
     * formulario es una sugerencia, no una garantía: sin esta comprobación una
     * empresa puede etiquetar sus conversaciones con la etiqueta de otra
     * mandando el id a mano.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function sanitizeSettings(array $input, int $companyId): array
    {
        return $this->defaultSettings();
    }

    /**
     * El manifiesto tal y como lo consume el frontend.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'slug' => $this->slug(),
            'name' => $this->name(),
            'description' => $this->description(),
            'detail' => $this->detail(),
            'icon' => $this->icon(),
            'category' => $this->category(),
            'permissions' => $this->permissions(),
            'hooks' => $this->hooks(),
        ];
    }
}
