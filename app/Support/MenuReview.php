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
    public static function build($menus, array $capabilities): array
    {
        $issues = [];

        // Un token revocado tumba TODOS los permisos a la vez, así que sacar un
        // aviso por cada opción y cada permiso llenaría la pantalla de ocho
        // líneas que dicen lo mismo y esconden las que sí son distintas. Es una
        // sola causa y una sola cosa que hacer: reconectar.
        $tokenDead = ($capabilities['error'] ?? null) !== null;

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

        if (($capabilities['connected'] ?? false) === false) {
            $issues[] = [
                'menu_id' => null,
                'menu' => null,
                'option_id' => null,
                'option' => null,
                'level' => self::BLOCKER,
                'says' => 'Tu software Integra no está conectado, así que las opciones de autoservicio derivarán al cliente a un asesor.',
                'fix' => 'Conéctalo con tu usuario y contraseña de Integra.',
                'action' => ['kind' => 'integrations', 'label' => 'Conectar Integra'],
            ];
        }

        // Los permisos que faltan se juntan de TODAS las opciones antes de
        // escribir nada: una empresa sin `contratos.leer` tiene diez opciones
        // que fallan por lo mismo, y diez líneas idénticas esconden las que sí
        // son distintas. Es un problema, no diez.
        $byMissing = [];

        foreach ($menus as $menu) {
            foreach ($menu->options as $option) {
                if (! $tokenDead && $missing = IntegraCapabilities::missingFor($option, $capabilities)) {
                    $key = implode('|', $missing);
                    $byMissing[$key]['missing'] = $missing;
                    $byMissing[$key]['options'][] = $option->title;
                }

                foreach (self::optionIssues($menu, $option, $menus) as $issue) {
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
    private static function optionIssues(
        WhatsAppMenu $menu,
        WhatsAppMenuOption $option,
        $menus
    ): array {
        $issues = [];

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
