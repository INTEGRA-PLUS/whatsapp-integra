<?php

use App\Support\DefaultWhatsAppMenu;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Las opciones tal como salieron de fábrica en la versión anterior de la
     * plantilla: con "Cambiar clave WiFi" y sin el submenú de contrato.
     *
     * Vive aquí y no en la plantilla porque es un dato del pasado. La empresa
     * que las conserve idénticas —mismo orden, mismo título, misma acción— es
     * que nunca entró a configurar nada, y se le puede poner al día sin perder
     * trabajo de nadie.
     */
    private const PREVIOUS = [
        ['📄 Consultar factura', 'consultar_factura'],
        ['💳 Pagar en línea', 'pagar_en_linea'],
        ['📶 Cambiar clave WiFi', 'cambiar_clave'],
        ['🛠️ Reportar falla', 'reportar_falla'],
        ['👤 Hablar con un asesor', 'handoff'],
    ];

    public function up(): void
    {
        DefaultWhatsAppMenu::refreshUntouched(self::PREVIOUS);
    }

    /**
     * No se revierte: devolver los menús a la plantilla vieja no arregla nada y
     * borraría el submenú de contrato de quien ya lo esté usando.
     */
    public function down(): void
    {
    }
};
