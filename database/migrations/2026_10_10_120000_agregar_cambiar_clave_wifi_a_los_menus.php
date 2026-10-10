<?php

use App\Models\WhatsAppMenuOption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DE DATOS, no de esquema: añade «🔐 Cambiar clave WiFi» a los menús en uso.
 *
 * El 10-oct-2026 «Cambiar clave WiFi» dejó de ser una opción «pendiente» y
 * pasó a cambiar la clave de verdad, con la API de Integra. Se pidió que les
 * saliera a los clientes de las empresas de Integra, que tienen el menú por
 * defecto con su submenú «Mi plan y contrato».
 *
 * Se añade una sola opción, justo debajo de «Mi clave WiFi», y no se toca nada
 * más: ni los textos ni el resto de opciones, que son de la empresa. Las
 * opciones siguientes se corren un puesto en su sitio —sin borrar ni recrear—
 * porque el id de cada opción viaja a WhatsApp (`wamenu:{menu}:{opción}`) y un
 * menú que el cliente ya tiene en el móvil dejaría de responder.
 *
 * Sólo en los submenús que tienen la opción de ver la clave (segmento «wifi»),
 * que todavía no la tienen, y que caben: WhatsApp no deja más de diez filas.
 * Si la empresa no enciende la extensión «Cambio de clave WiFi», la opción
 * pasa al cliente a un asesor, que es lo que habría pedido de todos modos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conLaClave = WhatsAppMenuOption::where('action_type', 'estado_servicio')
            ->get()
            ->filter(fn (WhatsAppMenuOption $o) => ($o->config['segmento'] ?? null) === 'wifi');

        foreach ($conLaClave as $verClave) {
            $menuId = $verClave->menu_id;

            $opciones = WhatsAppMenuOption::where('menu_id', $menuId)->orderBy('position')->get();

            if ($opciones->contains('action_type', 'cambiar_clave') || $opciones->count() >= 10) {
                continue;
            }

            // Ni si la empresa ya la tiene en otro menú: desde la siguiente
            // migración vive en el principal, y un entorno nuevo la traería
            // por partida doble.
            $empresa = \App\Models\WhatsAppMenu::whereKey($menuId)->value('company_id');
            $yaLaTiene = WhatsAppMenuOption::where('action_type', 'cambiar_clave')
                ->whereIn('menu_id', \App\Models\WhatsAppMenu::where('company_id', $empresa)->pluck('id'))
                ->exists();

            if ($yaLaTiene) {
                continue;
            }

            DB::transaction(function () use ($menuId, $verClave) {
                WhatsAppMenuOption::where('menu_id', $menuId)
                    ->where('position', '>', $verClave->position)
                    ->increment('position');

                WhatsAppMenuOption::create([
                    'menu_id' => $menuId,
                    'position' => $verClave->position + 1,
                    'title' => '🔐 Cambiar clave WiFi',
                    'description' => 'Pon una clave nueva a tu red, en las dos bandas',
                    'action_type' => 'cambiar_clave',
                ]);
            });
        }
    }

    public function down(): void
    {
        // A propósito no borra nada: para entonces el cliente puede tener el
        // menú con esta opción en el móvil, y borrarla lo dejaría sin respuesta.
    }
};
