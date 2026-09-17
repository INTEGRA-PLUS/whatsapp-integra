<?php

namespace App\Support;

use App\Models\Company;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Que el cliente elija: tu menú, o preguntar con sus palabras.
 *
 * Es el patrón que usa Bancolombia en su WhatsApp —«1. Buscar en menú · 2.
 * Preguntar, te respondo con inteligencia artificial»— y ya se podía montar a
 * mano con lo que hay: un menú de bienvenida con dos opciones, una que abre el
 * menú de siempre y otra con la acción «Que responda la IA». Lo que faltaba era
 * no tener que armarlo.
 *
 * ## Lo que NO hace
 *
 * **No toca el menú que ya tenías.** Conserva sus palabras clave y sigue
 * respondiendo a ellas; lo único que pierde es el saludo, que pasa a la puerta.
 * Así «menu» o «ayuda» siguen funcionando como siempre, y deshacer esto es
 * borrar un menú.
 *
 * ## Cuándo se ofrece
 *
 * Sólo con la IA de chats disponible. Sin ella no hay dos caminos que ofrecer:
 * la puerta sería una pregunta con una sola respuesta posible, y el menú tiene
 * que salir directo — que es justo lo que ya pasa hoy.
 */
class PuertaDeEntrada
{
    /** Títulos cortos a propósito: con dos opciones WhatsApp los pinta como botones (20 caracteres). */
    public const VER_EL_MENU = '📋 Ver las opciones';

    public const PREGUNTAR = '💬 Preguntar algo';

    /**
     * Lo que se le pide a la IA cuando el cliente elige preguntar.
     *
     * No es una pregunta del cliente —todavía no ha preguntado nada—: es la
     * instrucción de abrirle la conversación. Lo que escriba después ya no pasa
     * por aquí; cae por el camino normal y lo atiende la IA porque ningún menú
     * lo reconoce.
     */
    public const INVITACION = 'El cliente eligió preguntar con sus propias palabras y todavía no ha preguntado nada. '
        .'Salúdalo en una frase corta e invítalo a escribir su duda. No inventes información ni te adelantes a lo que va a preguntar.';

    /** El menú que saluda hoy, que es el que la puerta va a abrir. */
    public static function elQueSaluda(Company $company): ?WhatsAppMenu
    {
        return WhatsAppMenu::where('company_id', $company->id)
            ->where('is_root', true)
            ->has('options')
            ->get()
            ->first(fn (WhatsAppMenu $m) => in_array('welcome', (array) $m->match_types, true));
    }

    /**
     * ¿Tiene sentido ofrecerla?
     *
     * Hacen falta las tres: la IA disponible —sin ella no hay segundo camino—,
     * un menú que saludar que ofrecer detrás, y que no esté armada ya.
     */
    public static function seLePuedeOfrecer(Company $company): bool
    {
        if (! OrdenDeLaConversacion::de($company->id)['ia_chat']) {
            return false;
        }

        $saluda = self::elQueSaluda($company);

        return $saluda !== null && ! self::yaArmada($saluda);
    }

    /**
     * ¿El menú que saluda ya deja elegir?
     *
     * La señal es que entre sus opciones haya una de IA: eso es exactamente lo
     * que la puerta añade, y da igual que la armara este botón o el admin a
     * mano. Mirar un nombre de menú sería frágil —lo renombran— y volvería a
     * ofrecer el botón a quien ya lo tiene.
     */
    public static function yaArmada(WhatsAppMenu $saluda): bool
    {
        return $saluda->options->contains(
            fn (WhatsAppMenuOption $o) => $o->action_type === WhatsAppMenuOption::ACTION_IA
        );
    }

    /**
     * La arma. Devuelve el menú nuevo, o null si no había nada que abrir.
     */
    public static function armar(Company $company): ?WhatsAppMenu
    {
        $saluda = self::elQueSaluda($company);

        if ($saluda === null || self::yaArmada($saluda)) {
            return null;
        }

        return DB::transaction(function () use ($company, $saluda) {
            $puerta = WhatsAppMenu::create([
                'company_id' => $company->id,
                // La misma instancia que el que saludaba: si aquél valía sólo
                // para una línea, la puerta tiene que valer para esa misma.
                'instance_id' => $saluda->instance_id,
                'name' => 'Puerta de entrada',
                'body_text' => "¡Hola {name}! 👋\n¿Cómo prefieres que te ayude hoy?",
                'footer_text' => 'Elige una opción 👇',
                'is_root' => true,
                'match_types' => ['welcome'],
                'active' => true,
                'cooldown_minutes' => $saluda->cooldown_minutes ?? 60,
                'saludar_de_nuevo_horas' => $saluda->saludar_de_nuevo_horas ?? 24,
            ]);

            $puerta->options()->create([
                'position' => 0,
                'title' => self::VER_EL_MENU,
                'action_type' => 'submenu',
                'target_menu_id' => $saluda->id,
            ]);

            $puerta->options()->create([
                'position' => 1,
                'title' => self::PREGUNTAR,
                'action_type' => WhatsAppMenuOption::ACTION_IA,
                'reply_text' => self::INVITACION,
            ]);

            // El de siempre deja de saludar y conserva TODO lo demás: sus
            // palabras clave siguen abriéndolo, sus opciones no se tocan, y
            // deshacer esto es borrar la puerta.
            $saluda->dejaDeSaludar();

            Log::channel('whatsapp')->info('🚪 Puerta de entrada armada', [
                'company_id' => $company->id,
                'puerta_id' => $puerta->id,
                'abre' => $saluda->name,
            ]);

            return $puerta->load('options');
        });
    }
}
