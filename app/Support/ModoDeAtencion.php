<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\WhatsAppMenu;
use App\Services\WhatsAppChatAiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Cómo quiere atender esta empresa: a mano, con menú, con IA, o con las dos.
 *
 * Existe porque hasta ahora **eso no se elegía: se deducía**. Estaba repartido
 * entre el interruptor de la IA de chats, el de la de menús, el campo de
 * palabras clave de cada menú y el tipo de coincidencia `welcome`, que son
 * cuatro sitios distintos y ninguno se llama «cómo quiero atender». Nadie podía
 * responder «quiero atención manual» sin saber antes que eso significa apagar
 * dos interruptores y vaciar un campo que está en otra pantalla.
 *
 * ## Los cuatro
 *
 * - **`manual`** — no responde nada automático. Todo mensaje queda para una
 *   persona. Es lo que quiere quien vende por WhatsApp a pulso.
 * - **`menu`** — el cliente elige de una lista. Nada de IA.
 * - **`ia`** — la IA conversa desde el primer mensaje. Los menús siguen
 *   existiendo, pero sólo se abren si alguien los ofrece.
 * - **`menu_ia`** — el menú va delante y la IA recoge lo que ningún menú
 *   reconoce. Es lo que la mayoría acaba queriendo, y hasta ahora se conseguía
 *   por accidente.
 *
 * ## Lo que NO hace
 *
 * No toca los submenús. Un submenú no se dispara solo —se abre desde una opción
 * de otro menú— así que apagarlo rompería el menú que lo abre sin arreglar nada.
 * Sólo se encienden y apagan los menús que **saltan por su cuenta**.
 *
 * Y no se aplica nada sin enseñar antes qué va a cambiar: `loQueCambiaria()`
 * devuelve los nombres exactos. Un botón que apaga tres menús sin decir cuáles
 * es un botón que nadie se atreve a pulsar dos veces.
 */
class ModoDeAtencion
{
    public const MANUAL = 'manual';

    public const MENU = 'menu';

    public const IA = 'ia';

    public const MENU_IA = 'menu_ia';

    public const MODOS = [self::MANUAL, self::MENU, self::IA, self::MENU_IA];

    /** En cuál está hoy, mirando lo que de verdad está encendido. */
    public static function actual(Company $company): string
    {
        $orden = OrdenDeLaConversacion::de($company->id);

        $menu = $orden['hay_disparadores'] || $orden['saluda_con_menu'];
        $ia = $orden['ia_chat'] || $orden['ia_menus'];

        return match (true) {
            $menu && $ia => self::MENU_IA,
            $menu => self::MENU,
            $ia => self::IA,
            default => self::MANUAL,
        };
    }

    /**
     * Qué pasaría al cambiar a este modo, sin cambiar nada todavía.
     *
     * @return array{
     *     menus_a_encender: list<string>,
     *     menus_a_apagar: list<string>,
     *     ia: ?string,
     *     bloqueado: ?string
     * }
     */
    public static function loQueCambiaria(Company $company, string $modo): array
    {
        $quiereMenu = in_array($modo, [self::MENU, self::MENU_IA], true);
        $quiereIa = in_array($modo, [self::IA, self::MENU_IA], true);

        $saltan = self::menusQueSaltan($company->id);

        return [
            'menus_a_encender' => $quiereMenu
                ? $saltan->where('active', false)->pluck('name')->values()->all()
                : [],
            'menus_a_apagar' => ! $quiereMenu
                ? $saltan->where('active', true)->pluck('name')->values()->all()
                : [],
            'ia' => match (true) {
                $quiereIa && ! self::iaEncendida($company->id) => 'encender',
                ! $quiereIa && self::iaEncendida($company->id) => 'apagar',
                default => null,
            },
            'bloqueado' => $quiereIa ? self::porQueNoSePuedeEncenderLaIa($company) : null,
        ];
    }

    /**
     * Aplica el modo. Devuelve lo que cambió, ya hecho.
     *
     * @return array{menus_a_encender: list<string>, menus_a_apagar: list<string>, ia: ?string, bloqueado: ?string}
     */
    public static function aplicar(Company $company, string $modo): array
    {
        $cambios = self::loQueCambiaria($company, $modo);

        // Encender la IA sin plan o sin flujo configurado dejaría al admin con
        // un interruptor en verde y sin IA. Se dice y no se aplica nada.
        if ($cambios['bloqueado'] !== null) {
            return $cambios;
        }

        $quiereMenu = in_array($modo, [self::MENU, self::MENU_IA], true);
        $quiereIa = in_array($modo, [self::IA, self::MENU_IA], true);

        self::menusQueSaltan($company->id)->each(
            fn (WhatsAppMenu $m) => $m->update(['active' => $quiereMenu])
        );

        // Sólo el chat: es el flujo conversacional y el que se entiende como
        // «la IA». El de menús consulta Integra y se enciende aparte, en su
        // propia pantalla, porque necesita permisos que esto no puede dar.
        CompanyIntegration::updateOrCreate(
            ['company_id' => $company->id, 'key' => CompanyIntegration::KEY_AI_CHAT],
            ['enabled' => $quiereIa]
        );

        if (! $quiereIa) {
            CompanyIntegration::where('company_id', $company->id)
                ->where('key', CompanyIntegration::KEY_AI_MENUS)
                ->update(['enabled' => false]);
        }

        Log::channel('whatsapp')->info('⚙️ Modo de atención cambiado', [
            'empresa' => $company->id,
            'modo' => $modo,
            'menus_encendidos' => $cambios['menus_a_encender'],
            'menus_apagados' => $cambios['menus_a_apagar'],
        ]);

        return $cambios;
    }

    /**
     * Los menús que se disparan por su cuenta: con palabras clave o de
     * bienvenida.
     *
     * Los submenús quedan fuera a propósito. No se disparan solos —los abre una
     * opción de otro menú— así que apagarlos rompería el menú que los abre sin
     * cambiar en nada lo que recibe el cliente al escribir.
     *
     * @return Collection<int, WhatsAppMenu>
     */
    private static function menusQueSaltan(int $companyId)
    {
        return WhatsAppMenu::where('company_id', $companyId)
            ->get()
            ->filter(fn (WhatsAppMenu $m) => $m->keywords() !== []
                || array_intersect((array) ($m->match_types ?? []), WhatsAppMenu::TRIGGERLESS_TYPES) !== []
            )
            ->values();
    }

    private static function iaEncendida(int $companyId): bool
    {
        $orden = OrdenDeLaConversacion::de($companyId);

        return $orden['ia_chat'] || $orden['ia_menus'];
    }

    /** Por qué no se puede encender la IA, en palabras para el admin. */
    private static function porQueNoSePuedeEncenderLaIa(Company $company): ?string
    {
        if (! PlanDeLaEmpresa::de($company)->permiteFlujoIa('ai_chat')) {
            return 'La IA que conversa no está incluida en tu plan. Contacta con un administrador.';
        }

        if (! WhatsAppChatAiClient::configured()) {
            return 'Falta configurar el flujo de IA en el servidor. Avisa al equipo técnico.';
        }

        return null;
    }
}
