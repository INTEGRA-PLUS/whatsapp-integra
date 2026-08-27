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

    /**
     * Las opciones del menú, en el orden en que las ve el cliente.
     *
     * Las tres primeras se resuelven solas contra el software de facturación;
     * "Hablar con un asesor" va al final a propósito, porque quien llega hasta
     * ahí ya descartó que lo suyo fuera una factura o un corte.
     *
     * `radicado_servicio` se queda sin definir porque depende del catálogo de
     * cada empresa: el formulario lo pide en amarillo hasta que el admin elige
     * uno, y mientras tanto "Reportar falla" deriva a una persona.
     *
     * @return list<array<string, mixed>>
     */
    public static function options(): array
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
                'title' => '📶 Cambiar clave WiFi',
                'description' => 'Cambia la contraseña de tu red',
                'action_type' => 'cambiar_clave',
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
     * Crea el menú por defecto de una empresa.
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

            foreach (array_values(self::options()) as $position => $option) {
                $menu->options()->create($option + [
                    'position' => $position,
                    'reply_text' => null,
                    'config' => null,
                ]);
            }

            return $menu->load('options');
        });
    }
}
