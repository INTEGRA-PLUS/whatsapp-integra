<?php

namespace App\Support;

use App\Models\Company;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use Illuminate\Support\Facades\DB;

/**
 * El menú con el que arranca **cualquier** negocio.
 *
 * Hasta ahora toda empresa nueva nacía con el menú de ISP: consultar factura,
 * pagar en línea, reportar una falla, estado del contrato. Para un ISP con
 * Integra es perfecto; para una peluquería, una tienda o un consultorio son
 * cuatro opciones que no hacen nada y que hay que borrar a mano antes de poder
 * usar la pantalla.
 *
 * Este menú no consulta nada de fuera. Sus cuatro opciones responden con texto o
 * pasan a una persona, así que **funcionan desde el primer minuto sin conectar
 * ningún software**. Quien sea ISP aplica la plantilla de Integra con un botón y
 * recupera la otra.
 *
 * ## Por qué los textos son de relleno y se nota
 *
 * Porque un menú que ya trae escrito «Atendemos de lunes a viernes de 8 a 6» se
 * enciende tal cual, y entonces le promete a los clientes de la empresa un
 * horario que nadie comprobó. Los marcadores entre corchetes hacen imposible
 * encenderlo sin leerlo: es el único freno que funciona.
 *
 * Nace apagado por lo mismo. Ver `DefaultWhatsAppMenu` para la plantilla de ISP.
 */
class MenuGenerico
{
    public const NAME = 'Menú principal';

    /**
     * Lo que ve el cliente, en orden.
     *
     * «Hablar con una persona» va al final a propósito: quien llega ahí ya
     * descartó lo demás. Y va siempre, porque un menú sin salida humana deja
     * atrapado a quien necesita algo que no está en la lista.
     *
     * @return list<array<string, mixed>>
     */
    public static function options(): array
    {
        return [
            [
                'title' => 'Qué ofrecemos',
                'description' => 'Productos o servicios',
                'action_type' => 'reply_text',
                'reply_text' => "[Escribe aquí lo que vendes u ofreces.]\n\n"
                    .'Puedes poner una lista corta, con precios si quieres que se vean sin preguntar.',
            ],
            [
                'title' => 'Horarios y ubicación',
                'description' => 'Cuándo y dónde atendemos',
                'action_type' => 'reply_text',
                'reply_text' => "[Escribe aquí tus horarios y tu dirección.]\n\n"
                    .'Si tienes varias sedes, nómbralas con su dirección y su horario.',
            ],
            [
                'title' => 'Preguntas frecuentes',
                'description' => 'Lo que más nos preguntan',
                'action_type' => 'reply_text',
                'reply_text' => '[Escribe aquí las dos o tres preguntas que más te hacen, con su respuesta.]',
            ],
            [
                'title' => 'Hablar con una persona',
                'description' => 'Te atiende alguien del equipo',
                'action_type' => 'handoff',
                'reply_text' => 'Con gusto. Te paso con alguien del equipo; en un momento te escriben por aquí.',
                'config' => ['assign_strategy' => WhatsAppMenuOption::ASSIGN_LEAST_BUSY],
            ],
        ];
    }

    /**
     * Lo crea para una empresa que todavía no tiene menús.
     *
     * Devuelve `null` si ya tiene alguno: sembrar encima sería pisarle el
     * trabajo a quien ya armó el suyo.
     */
    public static function createFor(Company $company): ?WhatsAppMenu
    {
        if (WhatsAppMenu::where('company_id', $company->id)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($company) {
            $menu = WhatsAppMenu::create([
                'company_id' => $company->id,
                'instance_id' => null,
                'name' => self::NAME,
                'type' => 'button',
                'header_text' => null,
                'body_text' => "¡Hola! 👋 Gracias por escribirnos.\n\n¿Con qué te ayudamos?",
                'footer_text' => null,
                'list_button_text' => 'Ver opciones',
                'is_root' => true,
                // `welcome` para que salga en el primer mensaje, y las palabras
                // clave para que el cliente que ya va a mitad de conversación
                // pueda recuperarlo escribiendo «menu».
                'match_types' => ['welcome', 'contains'],
                'trigger_text' => 'menu, opciones, ayuda, inicio',
                // Apagado: encenderlo aquí pondría a los clientes de la empresa
                // a recibir un menú con marcadores entre corchetes sin escribir.
                'active' => false,
                'cooldown_minutes' => 60,
            ]);

            foreach (self::options() as $posicion => $opcion) {
                $menu->options()->create($opcion + ['position' => $posicion]);
            }

            return $menu->load('options');
        });
    }
}
