<?php

namespace App\Extensions;

use App\Extensions\Contracts\FiltersOutboundText;
use App\Models\CompanyExtension;
use App\Models\WhatsAppMessage;

/**
 * Cómo firma el equipo los mensajes que salen.
 *
 * Hasta ahora la plataforma anteponía «*Nombre:*» a TODOS los mensajes de texto
 * de TODAS las empresas, con el formato escrito a mano dentro de
 * DeliverWhatsAppMessage. No había forma de quitarlo, de firmar al final, de
 * añadir el cargo ni de poner la razón social: una decisión de producto
 * congelada en una línea de un job.
 *
 * Esto la saca de ahí. Quien no instale la extensión sigue viendo exactamente el
 * mismo formato de siempre: el job se queda con su texto por defecto mientras no
 * haya ninguna extensión de salida encendida.
 *
 * Sólo toca los mensajes de texto que escribe una persona. Las plantillas las
 * aprueba Meta palabra por palabra y meterles una firma las haría rechazar; los
 * envíos automáticos (menús, respuestas automáticas, campañas) no los firma
 * nadie porque no los escribió nadie.
 */
class AgentSignatureExtension extends Extension implements FiltersOutboundText
{
    /** Lo que la plataforma hacía antes de que esto existiera. */
    private const PLANTILLA_HISTORICA = '*{agente}:*';

    public function slug(): string
    {
        return 'agent_signature';
    }

    public function name(): string
    {
        return 'Firma automática del agente';
    }

    public function description(): string
    {
        return 'Decide cómo se firman los mensajes que tu equipo envía por WhatsApp.';
    }

    public function detail(): string
    {
        return 'Añade una firma configurable a los mensajes de texto que escriben tus agentes, '
            .'antes o después del mensaje. Puedes usar {agente} para el nombre de quien escribe y '
            .'{empresa} para el nombre de tu empresa.'
            ."\n\n"
            .'Sin esta extensión, la plataforma antepone «*Nombre del agente:*» y no hay forma de '
            .'cambiarlo. Al instalarla nace con ese mismo formato, así que instalarla no cambia '
            .'nada hasta que edites la plantilla.'
            ."\n\n"
            .'No toca las plantillas aprobadas por Meta (las rechazaría), ni los mensajes que envía '
            .'el sistema solo: menús, respuestas automáticas y campañas salen sin firma.';
    }

    public function icon(): string
    {
        return 'PenLine';
    }

    public function category(): string
    {
        return self::CATEGORIA_PRODUCTIVIDAD;
    }

    public function permissions(): array
    {
        return [
            'Modificar el texto de los mensajes salientes antes de enviarlos',
            'Leer el nombre del agente que escribe',
        ];
    }

    public function hooks(): array
    {
        return ['Al enviar un mensaje de texto, justo antes de salir hacia WhatsApp'];
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'template',
                'type' => 'text',
                'label' => 'Plantilla de la firma',
                'help' => 'Admite {agente} y {empresa}. Los asteriscos ponen el texto en negrita en WhatsApp.',
                'default' => self::PLANTILLA_HISTORICA,
                'maxlength' => 120,
            ],
            [
                'key' => 'position',
                'type' => 'select',
                'label' => 'Dónde va',
                'default' => 'before',
                'options' => [
                    ['value' => 'before', 'label' => 'Antes del mensaje'],
                    ['value' => 'after', 'label' => 'Después del mensaje'],
                ],
            ],
            [
                'key' => 'separator',
                'type' => 'select',
                'label' => 'Cómo se separa del mensaje',
                'default' => 'newline',
                'options' => [
                    ['value' => 'newline', 'label' => 'Salto de línea'],
                    ['value' => 'blank_line', 'label' => 'Línea en blanco'],
                    ['value' => 'space', 'label' => 'Un espacio'],
                ],
            ],
        ];
    }

    public function sanitizeSettings(array $input, int $companyId): array
    {
        $plantilla = trim((string) ($input['template'] ?? ''));

        return [
            // Una plantilla vacía es una forma legítima de decir "no firmes
            // nada", así que se respeta: es la única manera de quitar el
            // prefijo histórico, que era justamente lo que no se podía hacer.
            'template' => mb_substr($plantilla, 0, 120),
            'position' => ($input['position'] ?? null) === 'after' ? 'after' : 'before',
            'separator' => in_array($input['separator'] ?? null, ['newline', 'blank_line', 'space'], true)
                ? $input['separator']
                : 'newline',
        ];
    }

    public function filterOutboundText(
        string $text,
        WhatsAppMessage $message,
        CompanyExtension $installed
    ): string {
        // `sent_by` es lo que distingue "lo escribió una persona" de "lo mandó el
        // sistema": los menús, las respuestas automáticas y las campañas crean
        // sus mensajes sin remitente.
        if ($message->type !== 'text' || ! $message->sent_by) {
            return $text;
        }

        $settings = $installed->settings();
        $firma = $this->render($settings['template'] ?? '', $message);

        if ($firma === '' || trim($text) === '') {
            return $text;
        }

        $separador = match ($settings['separator'] ?? 'newline') {
            'blank_line' => "\n\n",
            'space' => ' ',
            default => "\n",
        };

        return ($settings['position'] ?? 'before') === 'after'
            ? $text.$separador.$firma
            : $firma.$separador.$text;
    }

    private function render(string $template, WhatsAppMessage $message): string
    {
        if (trim($template) === '') {
            return '';
        }

        $agente = $message->sender->name ?? 'Agente';
        $empresa = $message->conversation?->instance?->company?->name ?? '';

        return trim(strtr($template, [
            '{agente}' => $agente,
            '{empresa}' => $empresa,
        ]));
    }
}
