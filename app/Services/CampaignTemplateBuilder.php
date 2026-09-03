<?php

namespace App\Services;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;

/**
 * Convierte una campaña y un destinatario concretos en los `components` que
 * espera Meta, y en el texto que de verdad va a leer esa persona.
 *
 * La personalización vive en `variable_map`: por cada `{{n}}` de la plantilla se
 * guarda de dónde sale el dato —un texto fijo igual para todos, o un campo del
 * destinatario (su nombre, su teléfono, una columna del CSV)—. Resolverlo aquí,
 * y no en el job, permite que la vista previa del asistente muestre exactamente
 * el mismo mensaje que se enviará, con el primer destinatario real dentro.
 *
 * Forma de `variable_map`:
 *
 *   {
 *     "header": [{"source": "fixed", "value": "Septiembre"}],
 *     "body":   [{"source": "field", "field": "name"}, {"source": "fixed", "value": "$120.000"}]
 *   }
 */
class CampaignTemplateBuilder
{
    /** Campos del destinatario que se pueden insertar en una variable. */
    public const FIELDS = ['name', 'phone', 'identificacion'];

    public function components(WhatsAppCampaign $campaign, ?WhatsAppCampaignRecipient $recipient = null): array
    {
        $components = [];
        $map = $campaign->variable_map ?? [];

        $headerFormat = $this->headerFormat($campaign);

        if (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && $campaign->header_media_id) {
            $kind = strtolower($headerFormat);
            $media = ['id' => $campaign->header_media_id];

            if ($kind === 'document') {
                $media['filename'] = $campaign->header_filename ?: 'documento.pdf';
            }

            $components[] = [
                'type' => 'header',
                'parameters' => [['type' => $kind, $kind => $media]],
            ];
        } elseif ($headerFormat === 'TEXT' && !empty($map['header'])) {
            $components[] = [
                'type' => 'header',
                'parameters' => $this->parameters($map['header'], $recipient),
            ];
        }

        if (!empty($map['body'])) {
            $components[] = [
                'type' => 'body',
                'parameters' => $this->parameters($map['body'], $recipient),
            ];
        }

        return $components;
    }

    /**
     * El cuerpo ya resuelto, que es lo que se guarda como contenido de la burbuja
     * del chat: el agente debe leer lo mismo que le llegó al cliente.
     */
    public function preview(WhatsAppCampaign $campaign, ?WhatsAppCampaignRecipient $recipient = null): string
    {
        $body = null;
        foreach ($campaign->template_components ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') === 'BODY') {
                $body = (string) ($component['text'] ?? '');
                break;
            }
        }

        if ($body === null || $body === '') {
            return "[Plantilla: {$campaign->template_name}]";
        }

        return $this->fill($body, $campaign->variable_map['body'] ?? [], $recipient);
    }

    /**
     * Sustituye los {{n}} de un texto por los valores de este destinatario.
     */
    private function fill(string $texto, array $slots, ?WhatsAppCampaignRecipient $recipient): string
    {
        $values = array_map(fn ($slot) => $this->resolve($slot, $recipient), $slots);
        $i = 0;

        return preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            function ($matches) use ($values, &$i) {
                $key = $matches[1];
                $value = is_numeric($key)
                    ? ($values[((int) $key) - 1] ?? null)
                    : ($values[$i] ?? null);
                $i++;

                return ($value === null || $value === '') ? $matches[0] : $value;
            },
            $texto
        );
    }

    /**
     * El encabezado de texto, ya resuelto. Se pintaba crudo en el detalle de la
     * campaña —«Tu factura de {{1}}»—, que es justo lo que nadie recibió.
     */
    public function previewHeader(WhatsAppCampaign $campaign, ?WhatsAppCampaignRecipient $recipient = null): ?string
    {
        foreach ($campaign->template_components ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') !== 'HEADER') {
                continue;
            }

            if (strtoupper($component['format'] ?? 'TEXT') !== 'TEXT') {
                return null;
            }

            return $this->fill((string) ($component['text'] ?? ''), $campaign->variable_map['header'] ?? [], $recipient);
        }

        return null;
    }

    /**
     * Qué valor le toca a este destinatario en una variable.
     */
    public function resolve(array $slot, ?WhatsAppCampaignRecipient $recipient): string
    {
        $source = $slot['source'] ?? 'fixed';

        if ($source === 'fixed') {
            return $this->clean((string) ($slot['value'] ?? ''));
        }

        $field = (string) ($slot['field'] ?? '');
        $variables = $recipient?->variables ?? [];

        // Lo que trajo el CSV manda sobre lo derivado del contacto: si alguien se
        // molestó en escribir el dato para esta campaña, es el bueno.
        if (array_key_exists($field, $variables)) {
            return $this->clean((string) $variables[$field]);
        }

        $value = match ($field) {
            'name' => $recipient?->name ?: $recipient?->contact?->name ?: '',
            'phone' => $recipient?->phone_number ?: '',
            'identificacion' => $recipient?->contact?->identificacion ?: '',
            default => '',
        };

        return $this->clean((string) $value);
    }

    public function headerFormat(WhatsAppCampaign $campaign): ?string
    {
        foreach ($campaign->template_components ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') === 'HEADER') {
                return strtoupper($component['format'] ?? 'TEXT');
            }
        }

        return null;
    }

    /**
     * Cuántas variables distintas declara el cuerpo de la plantilla.
     */
    public function bodyVariableCount(array $templateComponents): int
    {
        foreach ($templateComponents as $component) {
            if (strtoupper($component['type'] ?? '') === 'BODY') {
                preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', (string) ($component['text'] ?? ''), $matches);
                return count(array_unique($matches[1] ?? []));
            }
        }

        return 0;
    }

    private function parameters(array $slots, ?WhatsAppCampaignRecipient $recipient): array
    {
        return array_map(
            fn ($slot) => ['type' => 'text', 'text' => $this->resolve($slot, $recipient)],
            $slots
        );
    }

    /**
     * WhatsApp rechaza los parámetros con saltos de línea, tabuladores o cuatro
     * espacios seguidos (132007). Se limpian aquí y no en el formulario: el dato
     * puede venir de un CSV o del CRM, no solo de alguien escribiendo.
     */
    private function clean(string $value): string
    {
        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        $value = preg_replace('/ {4,}/', ' ', $value);

        return trim($value);
    }
}
