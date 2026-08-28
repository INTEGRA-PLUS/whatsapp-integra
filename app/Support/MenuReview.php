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
     * @return list<array{menu_id: int, menu: string, option: ?string, level: string, says: string, fix: string}>
     */
    public static function build($menus, array $capabilities): array
    {
        $issues = [];

        foreach ($menus as $menu) {
            foreach ($menu->options as $option) {
                foreach (self::optionIssues($menu, $option, $capabilities, $menus) as $issue) {
                    $issues[] = $issue + ['menu_id' => $menu->id, 'menu' => $menu->name, 'option' => $option->title];
                }
            }
        }

        // Los bloqueos primero: son los que dejan al cliente sin respuesta.
        usort($issues, fn ($a, $b) => ($a['level'] === self::BLOCKER ? 0 : 1) <=> ($b['level'] === self::BLOCKER ? 0 : 1));

        return $issues;
    }

    /**
     * @param \Illuminate\Support\Collection<int, WhatsAppMenu> $menus
     * @return list<array{level: string, says: string, fix: string}>
     */
    private static function optionIssues(
        WhatsAppMenu $menu,
        WhatsAppMenuOption $option,
        array $capabilities,
        $menus
    ): array {
        $issues = [];

        foreach (IntegraCapabilities::missingFor($option, $capabilities) as $missing) {
            $issues[] = [
                'level' => self::BLOCKER,
                'says' => 'Tu token de Integra no puede «' . (IntegraCapabilities::LABELS[$missing] ?? $missing)
                    . '», así que esta opción no podrá responder y derivará al cliente a un asesor.',
                'fix' => 'Pide que reemitan el token de Integra con el permiso '
                    . (IntegraCapabilities::SCOPES[$missing] ?? $missing)
                    . ', o vuelve a conectarlo con tu usuario y contraseña desde Integraciones (así sale con todos).',
            ];
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
