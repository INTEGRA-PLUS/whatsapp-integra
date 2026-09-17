<?php

namespace App\Support;

use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Services\IntegraCapabilities;

/**
 * Qué le va a fallar al menú antes de que lo toque un cliente.
 *
 * El módulo tiene una trampa de origen: casi todo se puede guardar, y casi todo
 * falla en silencio. Una opción de Integra con un token sin el scope correcto,
 * un "Reportar falla" sin tipo de falla, un submenú apagado, una opción de
 * imagen sin imagen: el menú se guarda tan contento, el admin lo enciende, y lo
 * descubre el cliente. Los errores viven en los logs, donde el admin no mira.
 *
 * Aquí se dice antes y en su idioma: qué opción, qué le falta y qué hacer.
 *
 * Se distingue lo que impide responder (`blocker`) de lo que sólo degrada
 * (`warning`), porque mezclarlos convierte la revisión en una lista de la que
 * uno deja de hacer caso.
 */
class MenuReview
{
    public const BLOCKER = 'blocker';
    public const WARNING = 'warning';

    /**
     * @param \Illuminate\Support\Collection<int, WhatsAppMenu> $menus
     * @param array{connected: bool, checked: bool, can: array<string, bool>, error: ?string} $capabilities
     * @return list<array{menu_id: ?int, menu: ?string, option: ?string, level: string, says: string, fix: string, action: ?array}>
     */
    public static function build(
        $menus,
        array $capabilities,
        bool $usaIntegra = true,
        bool $iaDisponible = true
    ): array {
        $issues = [];

        // Con Integra desconectado, sus opciones ya no hacen lo que prometen:
        // el aviso de arriba lo dice una vez y derivan al asesor. Pedir además
        // que se les configure el tipo de falla o el enlace de pago es mandar a
        // rellenar campos que hoy no ejecutan nada, y entierra los avisos que sí
        // se pueden arreglar entre ruido que no.
        $integraConectado = (bool) ($capabilities['connected'] ?? false);

        // Una farmacia que heredó el menú de fábrica antiguo arrastra sus
        // opciones de autoservicio, y sin esto sería este panel el único sitio
        // que le habla de un ERP de ISPs. Para ella esas opciones son «pasar a
        // un asesor» y ya está: nada que revisar, nada que conectar.
        if (! $usaIntegra) {
            $integraConectado = false;
        }

        // Un token revocado tumba TODOS los permisos a la vez, así que sacar un
        // aviso por cada opción y cada permiso llenaría la pantalla de ocho
        // líneas que dicen lo mismo y esconden las que sí son distintas. Es una
        // sola causa y una sola cosa que hacer: reconectar.
        $tokenDead = $usaIntegra && ($capabilities['error'] ?? null) !== null;

        if ($tokenDead) {
            $issues[] = [
                'menu_id' => null,
                'menu' => null,
                'option_id' => null,
                'option' => null,
                'level' => self::BLOCKER,
                'says' => $capabilities['error'],
                'fix' => 'Reconéctalo con tu usuario y contraseña de Integra. El token nuevo sale con todos los permisos.',
                'action' => ['kind' => 'integrations', 'label' => 'Reconectar Integra'],
            ];
        }

        // Integra es un **extra**, no un requisito. Un menú responde
        // perfectamente sin él: responder con un mensaje, con una imagen, abrir
        // un submenú o pasar a un asesor no consultan nada de fuera. Este aviso
        // era un bloqueo rojo que decía «impide que tu menú responda», y hacía
        // pensar que sin Integra el menú no sirve — que es exactamente lo
        // contrario de lo que este producto quiere vender.
        //
        // Así que sólo aparece si la empresa **tiene puestas** opciones de
        // autoservicio, y aparece como aviso, no como bloqueo: esas opciones no
        // se quedan mudas, derivan a un asesor.
        $deAutoservicio = self::cuantasSonDeIntegra($menus);

        if ($usaIntegra && ! $integraConectado && $deAutoservicio > 0) {
            $issues[] = [
                'menu_id' => null,
                'menu' => null,
                'option_id' => null,
                'option' => null,
                'level' => self::WARNING,
                'says' => $deAutoservicio === 1
                    ? 'Tienes 1 opción de autoservicio que hoy deriva a un asesor en vez de resolverse sola.'
                    : 'Tienes '.$deAutoservicio.' opciones de autoservicio que hoy derivan a un asesor en vez de resolverse solas.',
                'fix' => 'Conecta Integra y las resuelven solas: consultar la factura, radicar la falla, enviar el enlace de pago. El resto del menú funciona igual sin conectarlo.',
                'action' => ['kind' => 'integrations', 'label' => 'Conectar Integra'],
            ];
        }

        // Los permisos que faltan se juntan de TODAS las opciones antes de
        // escribir nada: una empresa sin `contratos.leer` tiene diez opciones
        // que fallan por lo mismo, y diez líneas idénticas esconden las que sí
        // son distintas. Es un problema, no diez.
        $byMissing = [];

        foreach ($menus as $menu) {
            if ($aviso = self::submenuSinVuelta($menu, $menus)) {
                $issues[] = $aviso + [
                    'menu_id' => $menu->id,
                    'menu' => $menu->name,
                    'option_id' => null,
                    'option' => null,
                    'action' => ['kind' => 'menu', 'label' => 'Añadir la vuelta'],
                ];
            }

            foreach ($menu->options as $option) {
                if (! $tokenDead && $missing = IntegraCapabilities::missingFor($option, $capabilities)) {
                    $key = implode('|', $missing);
                    $byMissing[$key]['missing'] = $missing;
                    $byMissing[$key]['options'][] = $option->title;
                }

                foreach (self::optionIssues($menu, $option, $menus, $integraConectado, $iaDisponible) as $issue) {
                    $issues[] = $issue + [
                        'menu_id' => $menu->id,
                        'menu' => $menu->name,
                        // Con el id de la opción, el botón puede llevar al admin
                        // directamente a ella en vez de abrir el formulario y
                        // dejarle buscar cuál de las ocho era.
                        'option_id' => $option->id,
                        'option' => $option->title,
                        'action' => ['kind' => 'menu', 'label' => 'Corregir esta opción'],
                    ];
                }
            }
        }

        foreach ($byMissing as $group) {
            $issues[] = self::missingPermissionIssue($group['missing'], $group['options']);
        }

        // Los bloqueos primero: son los que dejan al cliente sin respuesta.
        usort($issues, fn ($a, $b) => ($a['level'] === self::BLOCKER ? 0 : 1) <=> ($b['level'] === self::BLOCKER ? 0 : 1));

        return $issues;
    }

    /**
     * Cuántas opciones de autoservicio hay puestas en los menús.
     *
     * Se cuenta para no avisar de Integra a quien no lo usa: una peluquería con
     * un menú de «Pedir cita / Ver precios / Hablar con alguien» no tiene por
     * qué leer nada sobre un ERP de ISPs.
     *
     * @param \Illuminate\Support\Collection<int, WhatsAppMenu> $menus
     */
    private static function cuantasSonDeIntegra($menus): int
    {
        return $menus->sum(
            fn (WhatsAppMenu $menu) => $menu->options->filter(
                fn (WhatsAppMenuOption $o) => array_key_exists(
                    (string) $o->action_type,
                    WhatsAppMenuOption::INTEGRA_ACTIONS
                )
            )->count()
        );
    }

    /**
     * Un permiso que falta, con todas las opciones que deja mudas.
     *
     * @param list<string> $missing
     * @param list<string> $options
     * @return array{menu_id: null, menu: null, option: null, level: string, says: string, fix: string, action: array}
     */
    private static function missingPermissionIssue(array $missing, array $options): array
    {
        $labels = array_map(fn ($m) => '«' . (IntegraCapabilities::LABELS[$m] ?? $m) . '»', $missing);
        $scopes = array_map(fn ($m) => IntegraCapabilities::SCOPES[$m] ?? $m, $missing);

        $options = array_values(array_unique($options));
        $shown = array_slice($options, 0, 4);
        $rest = count($options) - count($shown);

        $affected = count($options) === 1
            ? 'La opción «' . $shown[0] . '» no podrá responder'
            : count($options) . ' opciones no podrán responder (' . implode(', ', $shown)
                . ($rest > 0 ? ' y ' . $rest . ' más' : '') . ')';

        return [
            'menu_id' => null,
            'menu' => null,
            'option_id' => null,
            'option' => null,
            'level' => self::BLOCKER,
            'says' => 'Tu token de Integra no puede ' . self::enumerate($labels) . '. '
                . $affected . ': derivarán al cliente a un asesor.',
            'fix' => 'Reconecta Integra con tu usuario y contraseña: el token nuevo sale con todos los permisos. '
                . 'Si lo emites a mano, pide ' . self::enumerate($scopes, 'y') . '.',
            'action' => ['kind' => 'integrations', 'label' => 'Reconectar Integra'],
        ];
    }

    /**
     * «a», «b» ni «c» — con el conector delante del último, que es como se lee.
     * Va "ni" detrás de "no puede", y "y" cuando la frase es afirmativa.
     *
     * @param list<string> $items
     */
    private static function enumerate(array $items, string $connector = 'ni'): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }

        $last = array_pop($items);

        return implode(', ', $items) . " $connector " . $last;
    }

    /**
     * @param \Illuminate\Support\Collection<int, WhatsAppMenu> $menus
     * @return list<array{level: string, says: string, fix: string}>
     */
    /**
     * Un submenú del que no se puede volver.
     *
     * El cliente entra en «Crédito», ve cuatro cosas que no eran lo suyo, y se
     * queda ahí: para volver al menú principal tiene que acordarse de escribir
     * una palabra clave que nadie le ha dicho. Los menús buenos de WhatsApp
     * —Bancolombia, por ejemplo— cierran **siempre** con «Ir al menú anterior».
     *
     * Vale cualquiera de las dos salidas: una opción que abra otro menú, o una
     * que pase a un asesor. Lo que no vale es ninguna.
     *
     * Es un aviso y no un bloqueo: el menú responde perfectamente, sólo deja al
     * cliente en un callejón.
     *
     * @param \Illuminate\Support\Collection $menus
     * @return ?array{level: string, says: string, fix: string}
     */
    private static function submenuSinVuelta(WhatsAppMenu $menu, $menus): ?array
    {
        // Sólo los submenús: de un menú raíz se sale escribiendo, porque el
        // cliente llegó ahí escribiendo.
        if ($menu->is_root || $menu->options->isEmpty()) {
            return null;
        }

        // Un submenú al que no llega nadie es otro problema, y ya se avisa
        // desde la opción que lo abre.
        $alcanzable = $menus->contains(
            fn (WhatsAppMenu $m) => $m->options->contains(
                fn (WhatsAppMenuOption $o) => (int) $o->target_menu_id === (int) $menu->id
            )
        );

        if (! $alcanzable) {
            return null;
        }

        $tieneSalida = $menu->options->contains(
            fn (WhatsAppMenuOption $o) => in_array($o->action_type, ['submenu', 'handoff'], true)
        );

        if ($tieneSalida) {
            return null;
        }

        return [
            'level' => self::WARNING,
            'says' => 'De este submenú no se puede volver: el cliente que entra no tiene cómo salir.',
            'fix' => 'Añádele una última opción «Volver» con la acción «Abrir otro menú» apuntando al menú principal. También vale una que pase a un asesor.',
        ];
    }

    private static function optionIssues(
        WhatsAppMenu $menu,
        WhatsAppMenuOption $option,
        $menus,
        bool $integraConectado = true,
        bool $iaDisponible = true
    ): array {
        $issues = [];

        // Las de autoservicio no se revisan si Integra no está conectado: no se
        // ejecutan, y el aviso de que falta conectarlo ya está arriba.
        $esDeIntegra = array_key_exists(
            (string) $option->action_type,
            WhatsAppMenuOption::INTEGRA_ACTIONS
        );

        if ($esDeIntegra && ! $integraConectado) {
            return [];
        }

        if ($option->action_type === 'reportar_falla' && ! $option->setting('radicado_servicio')) {
            $issues[] = [
                'level' => self::BLOCKER,
                'says' => 'No tiene elegido el tipo de falla, así que no se puede crear el radicado.',
                'fix' => 'Edita la opción y elige el tipo de falla con el que entrarán los reportes.',
            ];
        }

        if ($option->action_type === WhatsAppMenuOption::ACTION_IMAGE && ! $option->imageUrl()) {
            $issues[] = [
                'level' => self::BLOCKER,
                'says' => 'Es una opción de imagen y no tiene imagen: el cliente sólo recibirá el pie de foto.',
                'fix' => 'Edita la opción y sube la imagen.',
            ];
        }

        if ($option->action_type === 'submenu') {
            $target = $menus->firstWhere('id', $option->target_menu_id);

            if (! $target) {
                $issues[] = [
                    'level' => self::BLOCKER,
                    'says' => 'Apunta a un submenú que ya no existe.',
                    'fix' => 'Edita la opción y elige un submenú, o cámbiale la acción.',
                ];
            } elseif (! $target->active) {
                $issues[] = [
                    'level' => self::BLOCKER,
                    'says' => 'El submenú «' . $target->name . '» está apagado, así que al tocarla no llega nada.',
                    'fix' => 'Enciende el submenú. No se dispara solo por estar encendido: sólo se abre desde aquí.',
                ];
            } elseif ($target->options->isEmpty()) {
                $issues[] = [
                    'level' => self::BLOCKER,
                    'says' => 'El submenú «' . $target->name . '» no tiene opciones.',
                    'fix' => 'Añádele opciones al submenú.',
                ];
            }
        }

        if ($option->action_type === 'reply_text' && trim((string) $option->reply_text) === '') {
            $issues[] = [
                'level' => self::BLOCKER,
                'says' => 'Responde con un mensaje, pero el mensaje está vacío.',
                'fix' => 'Escribe lo que recibirá el cliente.',
            ];
        }

        // Una opción de IA sobrevive a que la IA se apague, y es lo correcto:
        // borrarla al vencer la suscripción haría perder el trabajo de armarla.
        // Pero entonces deja de hacer lo que su título promete y pasa a un
        // asesor, y eso hay que decirlo aquí y no descubrirlo por el log.
        if ($option->action_type === WhatsAppMenuOption::ACTION_IA && ! $iaDisponible) {
            $issues[] = [
                'level' => self::WARNING,
                'says' => 'La contesta la IA, pero «IA en los chats» está apagada: hoy esta opción pasa el chat a un asesor.',
                'fix' => 'Enciende «IA en los chats» en «Ajustes de la IA»: desde ahí funciona dentro del menú sin cambiar quién atiende el primer mensaje. O cámbiale la acción.',
            ];
        }

        if ($option->action_type === WhatsAppMenuOption::ACTION_NONE) {
            $issues[] = [
                'level' => self::WARNING,
                'says' => 'No tiene acción: el cliente la ve, la toca y no recibe nada.',
                'fix' => 'Dale una acción o quítala del menú antes de encenderlo.',
            ];
        }

        if ($option->action_type === 'pagar_en_linea' && ! $option->setting('payment_url')) {
            $issues[] = [
                'level' => self::WARNING,
                'says' => 'No tiene enlace de pago: el cliente verá cuánto debe pero no tendrá dónde pagar.',
                'fix' => 'Añade tu enlace de pago, o ignóralo si tu sistema se lo manda al recibir el webhook.',
            ];
        }

        if ($option->isPending()) {
            $issues[] = [
                'level' => self::WARNING,
                'says' => 'Todavía no existe la integración detrás: responde un aviso de «próximamente».',
                'fix' => 'Escríbele tu propio texto para que el cliente sepa a dónde acudir mientras tanto.',
            ];
        }

        return $issues;
    }
}
