<?php

use App\Models\WhatsAppMenuOption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DE DATOS: sube «🔐 Cambiar clave WiFi» del submenú al menú principal.
 *
 * La migración anterior (2026_10_10_120000) la puso en «Mi plan y contrato»,
 * debajo de «Mi clave WiFi». Ese mismo día se pidió sacarla al menú principal:
 * es de lo más atractivo que tiene el bot y en el submenú no la encuentra nadie.
 *
 * Se MUEVE la fila —cambia de menú y de puesto— en vez de borrarla y crear
 * otra: al tocar una opción se busca por su id, así que los menús que ya se
 * enviaron con ella en el submenú siguen respondiendo. Va justo encima de la
 * opción que abre «Mi plan y contrato», y sólo si el principal tiene sitio
 * (WhatsApp no deja más de diez filas); si no, se queda donde estaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enSubmenus = WhatsAppMenuOption::where('action_type', 'cambiar_clave')
            ->where('title', '🔐 Cambiar clave WiFi')
            ->get();

        foreach ($enSubmenus as $opcion) {
            $enlace = WhatsAppMenuOption::where('action_type', 'submenu')
                ->where('target_menu_id', $opcion->menu_id)
                ->orderBy('id')
                ->first();

            if (! $enlace || WhatsAppMenuOption::where('menu_id', $enlace->menu_id)->count() >= 10) {
                continue;
            }

            if (WhatsAppMenuOption::where('menu_id', $enlace->menu_id)->where('action_type', 'cambiar_clave')->exists()) {
                continue;
            }

            DB::transaction(function () use ($opcion, $enlace) {
                $submenu = $opcion->menu_id;
                $puestoViejo = $opcion->position;

                WhatsAppMenuOption::where('menu_id', $enlace->menu_id)
                    ->where('position', '>=', $enlace->position)
                    ->increment('position');

                $opcion->update(['menu_id' => $enlace->menu_id, 'position' => $enlace->position]);

                WhatsAppMenuOption::where('menu_id', $submenu)
                    ->where('position', '>', $puestoViejo)
                    ->decrement('position');
            });
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás a propósito: moverla otra vez cambiaría el menú
        // que el cliente ya tiene en el móvil sin que nadie lo pidiera.
    }
};
