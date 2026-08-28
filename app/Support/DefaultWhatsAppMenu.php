<?php

namespace App\Support;

use App\Models\Company;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use Illuminate\Support\Facades\DB;

/**
 * El menú con el que arranca toda empresa.
 *
 * Un módulo que empieza con la pantalla vacía casi nunca se usa: el admin entra,
 * ve "Aún no tienes menús" y se va a hacer otra cosa. Con el menú ya armado, lo
 * que le queda es leerlo, cambiar los textos a su gusto y encenderlo.
 *
 * Nace apagado a propósito. Encenderlo por su cuenta significaría que los
 * clientes de la empresa empiezan a recibir un menú automático que nadie de esa
 * empresa revisó, y un mensaje enviado a un cliente ya no se puede recoger.
 */
class DefaultWhatsAppMenu
{
    public const NAME = 'Menú principal';
    public const SUBMENU_NAME = 'Estado de mi contrato';

    /**
     * Las opciones del menú principal, en el orden en que las ve el cliente.
     *
     * Las tres primeras se resuelven solas contra el software de facturación;
     * "Hablar con un asesor" va al final a propósito, porque quien llega hasta
     * ahí ya descartó que lo suyo fuera una factura o un corte.
     *
     * `radicado_servicio` se queda sin definir porque depende del catálogo de
     * cada empresa: el formulario lo pide en amarillo hasta que el admin elige
     * uno, y mientras tanto "Reportar falla" deriva a una persona.
     *
     * @param int $submenuId Menú al que lleva "Estado de mi contrato".
     * @return list<array<string, mixed>>
     */
    public static function options(int $submenuId): array
    {
        return [
            [
                'title' => '📄 Consultar factura',
                'description' => 'Tus facturas pendientes y el total a pagar',
                'action_type' => 'consultar_factura',
            ],
            [
                'title' => '💳 Pagar en línea',
                'description' => 'Genera tu enlace de pago al instante',
                'action_type' => 'pagar_en_linea',
            ],
            [
                'title' => '📶 Estado de mi contrato',
                'description' => 'Internet, plan, velocidad y fechas de corte',
                'action_type' => 'submenu',
                'target_menu_id' => $submenuId,
            ],
            [
                'title' => '🛠️ Reportar falla',
                'description' => 'Sin internet, señal intermitente o TV sin imagen',
                'action_type' => 'reportar_falla',
                'config' => ['radicado_prioridad' => 2],
            ],
            [
                'title' => '👤 Hablar con un asesor',
                'description' => 'Te atiende una persona del equipo',
                'action_type' => 'handoff',
                'reply_text' => 'Con gusto 🙌 Te comunico con un asesor, en un momento te escribe.',
                'config' => ['assign_strategy' => WhatsAppMenuOption::ASSIGN_LEAST_BUSY],
            ],
        ];
    }

    /**
     * Las opciones del submenú de contrato.
     *
     * Las cuatro salen de la misma consulta a Integra —una sola llamada trae
     * internet, plan, fechas y facturas—; lo que cambia es qué se le cuenta al
     * cliente. Preguntar antes de responder es lo que evita el muro de texto
     * que nadie lee.
     *
     * @return list<array<string, mixed>>
     */
    public static function submenuOptions(): array
    {
        return [
            [
                'title' => '🌐 Estado de internet',
                'description' => '¿Está activo o suspendido? ¿Por qué?',
                'action_type' => 'estado_servicio',
                'config' => ['segmento' => 'internet'],
            ],
            [
                'title' => '📄 Facturas pendientes',
                'description' => 'Lo que debes en este contrato',
                'action_type' => 'estado_servicio',
                'config' => ['segmento' => 'facturas'],
            ],
            [
                'title' => '⚡ Mi plan y velocidad',
                'description' => 'Megas contratadas, tecnología y valor mensual',
                'action_type' => 'estado_servicio',
                'config' => ['segmento' => 'plan'],
            ],
            [
                'title' => '📅 Cuándo me cortan',
                'description' => 'Periodo de facturación y fecha de corte',
                'action_type' => 'estado_servicio',
                'config' => ['segmento' => 'corte'],
            ],
        ];
    }

    /**
     * Crea el menú por defecto de una empresa, con su submenú de contrato.
     *
     * Devuelve null si la empresa ya tiene menús: quien ya configuró los suyos
     * no necesita que le aparezca uno más, y sobrescribir su trabajo sería
     * mucho peor que no hacer nada. Esto es además lo que deja correr la
     * migración dos veces sin duplicar nada.
     */
    public static function createFor(Company $company): ?WhatsAppMenu
    {
        if (WhatsAppMenu::where('company_id', $company->id)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($company) {
            $submenu = self::createSubmenu($company);
            $menu = self::createRoot($company, $submenu->id);

            return $menu->load('options');
        });
    }

    /**
     * Rehace los menús de fábrica que nadie ha tocado todavía, para que una
     * empresa que recibió una versión anterior de la plantilla se ponga al día.
     *
     * Hace falta porque createFor() se salta a toda empresa que ya tenga menús:
     * sin esto, quien sembró antes de un cambio se quedaba con la versión vieja
     * para siempre.
     *
     * Sólo se rehace lo demostrablemente intacto: menú apagado, que nunca se ha
     * enviado, sin instancia, único de la empresa y con sus opciones tal cual
     * salieron de fábrica. En cuanto el admin cambia una palabra se le deja en
     * paz — vale más su trabajo que tener la plantilla al día.
     *
     * @param list<array{0: string, 1: string}> $seeded Título y acción de cada
     *        opción en la versión anterior, en orden. Lo aporta quien llama,
     *        porque es un dato del pasado y no de la plantilla actual.
     * @return int Cuántas empresas se pusieron al día.
     */
    public static function refreshUntouched(array $seeded): int
    {
        $updated = 0;

        WhatsAppMenu::where('name', self::NAME)
            ->where('is_root', true)
            ->where('active', false)
            ->where('fires_count', 0)
            ->whereNull('instance_id')
            ->with('options')
            ->get()
            ->each(function (WhatsAppMenu $menu) use ($seeded, &$updated) {
                if (! self::isPristine($menu, $seeded)) {
                    return;
                }

                $company = Company::find($menu->company_id);

                if (! $company) {
                    return;
                }

                DB::transaction(function () use ($menu, $company) {
                    // Las opciones se van con el menú por la clave foránea, y
                    // con ellas el "ya tiene menús" que bloqueaba la siembra.
                    $menu->delete();
                    self::createFor($company);
                });

                $updated++;
            });

        return $updated;
    }

    /** @param list<array{0: string, 1: string}> $seeded */
    private static function isPristine(WhatsAppMenu $menu, array $seeded): bool
    {
        // Un menú suelto en la empresa: si hay más, alguien ya estuvo armando
        // cosas y borrar por debajo sería peor que dejarlo desactualizado.
        if (WhatsAppMenu::where('company_id', $menu->company_id)->count() !== 1) {
            return false;
        }

        $current = $menu->options
            ->sortBy('position')
            ->map(fn (WhatsAppMenuOption $o) => [$o->title, $o->action_type])
            ->values()
            ->all();

        return $current === $seeded;
    }

    private static function createRoot(Company $company, int $submenuId): WhatsAppMenu
    {
        $menu = WhatsAppMenu::create([
            'company_id' => $company->id,
            // Sin instancia: sirve para todas las líneas de la empresa, que
            // además es lo único posible cuando la empresa acaba de nacer y
            // todavía no ha conectado ninguna.
            'instance_id' => null,
            'name' => self::NAME,
            'header_text' => mb_substr($company->name, 0, WhatsAppMenu::MAX_HEADER),
            'body_text' => "¡Hola {name}! 👋\n\n¿En qué puedo ayudarte hoy? Elige una opción y te atiendo al instante.",
            'footer_text' => 'Escribe MENU cuando quieras volver aquí',
            'list_button_text' => 'Ver opciones',
            'is_root' => true,
            // Bienvenida y palabra clave a la vez: sale solo en el primer
            // contacto, y el cliente que ya va a mitad de conversación puede
            // recuperarlo escribiendo "menu".
            'match_types' => ['welcome', 'contains'],
            'trigger_text' => 'menu, opciones, ayuda, inicio',
            'active' => false,
            'cooldown_minutes' => 60,
        ]);

        self::addOptions($menu, self::options($submenuId));

        return $menu;
    }

    /**
     * El submenú nace ACTIVO aunque el principal esté apagado, y no es un
     * descuido: al no ser menú raíz nunca se dispara por su cuenta, así que
     * mientras el principal siga apagado no llega a nadie. Crearlo apagado
     * sería la trampa: el admin enciende el menú principal, el cliente toca
     * "Estado de mi contrato" y no recibe el submenú porque está inactivo.
     */
    private static function createSubmenu(Company $company): WhatsAppMenu
    {
        $submenu = WhatsAppMenu::create([
            'company_id' => $company->id,
            'instance_id' => null,
            'name' => self::SUBMENU_NAME,
            'header_text' => null,
            'body_text' => "📶 ¿Qué quieres revisar de tu servicio?",
            'footer_text' => 'Escribe MENU para volver al inicio',
            'list_button_text' => 'Ver opciones',
            'is_root' => false,
            'match_types' => [],
            'trigger_text' => null,
            'active' => true,
            'cooldown_minutes' => 0,
        ]);

        self::addOptions($submenu, self::submenuOptions());

        return $submenu;
    }

    /** @param list<array<string, mixed>> $options */
    private static function addOptions(WhatsAppMenu $menu, array $options): void
    {
        foreach (array_values($options) as $position => $option) {
            $menu->options()->create($option + [
                'position' => $position,
                'reply_text' => null,
                'target_menu_id' => null,
                'config' => null,
            ]);
        }
    }
}
