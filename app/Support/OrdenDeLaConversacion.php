<?php

namespace App\Support;

use App\Models\BusinessHour;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\WhatsAppMenu;

/**
 * Cómo está configurada hoy esta empresa para atender un mensaje entrante.
 *
 * No es lógica de negocio: es lo que las pantallas necesitan para poder
 * **contarle al admin qué le va a pasar a un cliente que escriba**. La pregunta
 * que lo motivó —«¿lo primero que sale es un menú o la IA?»— no tenía respuesta
 * en ninguna parte, y la respuesta importa: manda el menú y la IA es el último
 * recurso. Quien no lo sabe enciende la IA, escribe «hola», recibe un menú y
 * concluye que la IA no funciona.
 *
 * **El orden de verdad vive en `WhatsAppMenuService::handleInbound()`.** Esto
 * sólo mira qué piezas tiene puestas la empresa. Si allí cambia el orden, aquí
 * no hay nada que tocar; lo que hay que tocar es el texto de la pantalla.
 *
 * La distinción que más cuenta es `hay_disparadores`: un menú **sin palabras
 * clave** no se dispara solo, así que existir no basta. Es la diferencia entre
 * «tus clientes verán un menú» y «tus clientes hablarán con la IA», y se decide
 * en un campo que casi nadie relaciona con eso.
 */
class OrdenDeLaConversacion
{
    /** @return array<string, bool> */
    public static function de(int $companyId): array
    {
        // `whatsapp_menus` SÍ tiene `company_id` —a diferencia de sus opciones,
        // que se aíslan saltando por el menú— así que se filtra directo, igual
        // que hace `WhatsAppMenuController`.
        $menus = WhatsAppMenu::where('company_id', $companyId)
            ->where('active', true)
            ->get(['id', 'trigger_text', 'match_types']);

        $plan = PlanDeLaEmpresa::de(Company::find($companyId));

        return [
            'hay_menus' => $menus->isNotEmpty(),

            // Un menú activo pero sin palabras clave no se dispara nunca por su
            // cuenta: sólo sirve como submenú o como respuesta a otra cosa.
            'hay_disparadores' => $menus->contains(fn (WhatsAppMenu $m) => $m->keywords() !== []),

            // **El menú de bienvenida es otra cosa y es la que más confunde.**
            // El tipo `welcome` no necesita ninguna palabra clave: salta en el
            // primer mensaje de cada conversación, escriba el cliente lo que
            // escriba. Es la razón de que alguien encienda la IA, mande «hola»
            // y reciba un menú — y de que crea que la IA no funciona.
            'saluda_con_menu' => $menus->contains(
                fn (WhatsAppMenu $m) => array_intersect(
                    (array) ($m->match_types ?? []),
                    WhatsAppMenu::TRIGGERLESS_TYPES
                ) !== []
            ),

            'horarios' => BusinessHour::where('company_id', $companyId)->exists(),

            // Encendidas **y** permitidas por el plan: una integración que
            // quedó en `enabled` de cuando la empresa tenía el complemento no
            // responde, y pintarla como activa manda a buscar el fallo donde
            // no está.
            'ia_menus' => self::encendida($companyId, CompanyIntegration::KEY_AI_MENUS)
                && $plan->permiteFlujoIa('ai_menus'),

            'ia_chat' => self::encendida($companyId, CompanyIntegration::KEY_AI_CHAT)
                && $plan->permiteFlujoIa('ai_chat'),
        ];
    }

    private static function encendida(int $companyId, string $key): bool
    {
        return CompanyIntegration::where('company_id', $companyId)
            ->where('key', $key)
            ->where('enabled', true)
            ->exists();
    }
}
