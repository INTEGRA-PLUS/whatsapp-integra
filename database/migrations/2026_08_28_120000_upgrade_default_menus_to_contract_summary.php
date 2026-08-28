<?php

use App\Support\DefaultWhatsAppMenu;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * El menú raíz tal como salió de fábrica en la versión anterior: cinco
     * opciones, con el submenú llamado "Estado de mi contrato".
     *
     * Vive aquí y no en la plantilla porque es un dato del pasado. La empresa
     * que lo conserve idéntico —mismo orden, mismo título, misma acción— es que
     * nunca entró a configurar nada, y se le puede poner al día sin perderle
     * trabajo a nadie.
     */
    private const PREVIOUS_ROOT = [
        ['📄 Consultar factura', 'consultar_factura'],
        ['💳 Pagar en línea', 'pagar_en_linea'],
        ['📶 Estado de mi contrato', 'submenu'],
        ['🛠️ Reportar falla', 'reportar_falla'],
        ['👤 Hablar con un asesor', 'handoff'],
    ];

    /**
     * Y su submenú, que hasta ahora ninguna migración comprobaba.
     *
     * Sin esto la puesta al día no ocurriría: refreshUntouched() exige que la
     * empresa conserve la plantilla ENTERA, y desde que la plantilla siembra
     * dos menús, mirar sólo el raíz dejaba fuera a todo el mundo.
     */
    private const PREVIOUS_SUBMENUS = [
        'Estado de mi contrato' => [
            ['🌐 Estado de internet', 'estado_servicio'],
            ['📄 Facturas pendientes', 'estado_servicio'],
            ['⚡ Mi plan y velocidad', 'estado_servicio'],
            ['📅 Cuándo me cortan', 'estado_servicio'],
        ],
    ];

    public function up(): void
    {
        DefaultWhatsAppMenu::refreshUntouched(self::PREVIOUS_ROOT, self::PREVIOUS_SUBMENUS);
    }

    /**
     * No se revierte: devolver los menús a la plantilla vieja no arregla nada y
     * le quitaría a quien ya lo esté usando el consumo, los pagos y los
     * reportes abiertos.
     */
    public function down(): void
    {
    }
};
